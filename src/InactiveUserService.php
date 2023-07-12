<?php

namespace Drupal\inactive_user;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;

/**
 * Class InactiveUserService.
 */
class InactiveUserService implements InactiveUserServiceInterface {

  use StringTranslationTrait;

  /**
   * Symfony\Component\DependencyInjection\ContainerInterface definition.
   *
   * @var \Symfony\Component\DependencyInjection\ContainerInterface
   */
  protected $serviceContainer;

  /**
   * Drupal\Core\Database\Driver\mysql\Connection definition.
   *
   * @var \Drupal\Core\Database\Driver\mysql\Connection
   */
  protected $database;

  /**
   * Drupal\Core\Config\ConfigFactoryInterface definition.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Drupal\Core\Datetime\DateFormatterInterface definition.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Drupal\Core\Logger\LoggerChannelFactoryInterface definition.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Inactive user config.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * The site name.
   *
   * @var string
   */
  protected $siteName;

  /**
   * Configure service dependencies.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The Container object.
   */
  public function configure(ContainerInterface $container) {
    $this->serviceContainer = $container;
    $this->database = $container->get('database');
    $this->configFactory = $container->get('config.factory');
    $this->dateFormatter = $container->get('date.formatter');
    $this->loggerFactory = $container->get('logger.factory');
    $this->config = $this->configFactory->getEditable('inactive_user.inactiveuseradmin');
    $this->getSiteName();
  }

  /**
   * {@inheritdoc}
   */
  public function runCron(bool $test = FALSE) {
    $state = $this->serviceContainer->get('state');
    $last_run = $state->get('inactive_user_timestamp', 0);
    $request_time = \Drupal::time()->getRequestTime();

    if ($test || ($request_time - $last_run) >= INACTIVE_USER_DAY_MINUS_FIVE_MINUTES) {
      $state->set('inactive_user_timestamp', $request_time);
      $this->resetAdminNotifications();
      $this->notifyAdmin();
      $this->notifyUser();
      $this->warnedUserBlockTimestamp();
      $this->notifyUserBlock();
      $this->warnedUserDeleteTimestamp();
      $this->autoUserDelete();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function resetAdminNotifications() {
    // Reset notifications if recent user activity.
    $query = $this->database->select('users_field_data', 'u');
    $query->fields('u', ['uid', 'name']);
    // User is not admin or anonym.
    $query->condition('u.uid', 1, '>');
    // Has the admin been notified.
    $query->condition('u.notified_admin', 1);
    // User activiti is after than week ago.
    $request_time = \Drupal::time()->getRequestTime();
    $query->condition('u.access', $request_time - INACTIVE_USER_ONE_WEEK, '>');

    $result = $query->execute()->fetchAllAssoc('uid');
    if (count($result) > 0) {
      foreach ($result as $record) {
        $this->loggerFactory->get('user')->notice('recent user activity: %user removed from inactivity list', ['%user' => $record->name]);
      }
      $query = $this->database->update('users_field_data');
      $query->fields(['notified_admin' => 0]);
      $query->condition('uid', array_keys($result), 'in');
      $query->execute();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function notifyAdmin() {
    // Notify administrator of inactive user accounts.
    if ($notify_time = $this->config->get('inactive_user_notify_admin')) {
      $request_time = \Drupal::time()->getRequestTime();
      $query = $this->database->select('users_field_data', 'u');
      $query->fields('u', ['uid', 'name', 'mail', 'access', 'created']);

      // User is logged in and used the site.
      // Last use is earlier than block notify time.
      $and_condition1 = $query->andConditionGroup()
        ->condition('u.access', 0, '<>')
        ->condition('u.login', 0, '<>')
        ->condition('u.access', $request_time - $notify_time, '<');

      // User never logged in. User created earlier than notify time.
      $and_condition2 = $query->andConditionGroup()
        ->condition('u.login', 0)
        ->condition('u.created', $request_time - $notify_time, '<');

      // Has not the admin been notified.
      $or_condition1 = $query->orConditionGroup()
        ->isNull('u.notified_admin')
        ->condition('u.notified_admin', 0);

      // User never logged in. User created earlier than notify time.
      $or_condition2 = $query->orConditionGroup()
        ->condition($and_condition1)
        ->condition($and_condition2);

      // Add or condition groups to query.
      $query->condition($or_condition1);
      $query->condition($or_condition2);

      // User is not admin or anonym.
      $query->condition('u.uid', 1, '>');

      // Adds queryTag to identify this query in a custom module using the
      // hook_query_TAG_alter().
      // The first tag is a general identifier so you can include all the
      // queries that are being processed in this hook_cron().
      // The second tag is unique and only used to make changes to this
      // particular query.
      $query->addTag('inactive_user');
      $query->addTag('notified_admin');

      $results = $query->execute();

      $user_list = '';
      $uids = [];
      foreach ($results as $user) {
        if ($user->uid && ($user->access < ($request_time - $notify_time))) {
          $uids[] = $user->uid;
          $user_list .= "$user->name ($user->mail) last active on " . $this->dateFormatter->format($user->access, 'large') . ".\n";
        }
      }
      if (!empty($uids)) {
        // Update queries return rows updated.
        $query = $this->database->update('users_field_data');
        $query->fields(['notified_admin' => 1]);
        $query->condition('uid', $uids, 'in');
        $query->execute();

        $this->mail($this->t('[@sitename] Inactive users', ['@sitename' => $this->siteName]), $this->getMailText('notify_admin_text'), $notify_time, NULL, $user_list);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function notifyUser() {
    // Notify users that their account has been inactive.
    if ($notify_time = $this->config->get('inactive_user_notify')) {
      $request_time = \Drupal::time()->getRequestTime();
      $query = $this->database->select('users_field_data', 'u');
      $query->fields('u', ['uid', 'name', 'mail', 'access', 'created']);

      // User is logged in and used the site.
      // Last use is earlier than block notify time.
      $and_condition1 = $query->andConditionGroup()
        ->condition('u.access', 0, '<>')
        ->condition('u.login', 0, '<>')
        ->condition('u.access', $request_time - $notify_time, '<');

      // OR user never logged in. User created earlier than notify time.
      $and_condition2 = $query->andConditionGroup()
        ->condition('u.login', 0)
        ->condition('u.created', $request_time - $notify_time, '<');

      // AND has not the user been notified.
      $or_condition1 = $query->orConditionGroup()
        ->isNull('u.notified_user')
        ->condition('u.notified_user', 0);

      // Add above conditions to or condition group.
      $or_condition2 = $query->orConditionGroup()
        ->condition($and_condition1)
        ->condition($and_condition2);

      // Add or condition groups to query.
      $query->condition($or_condition1);
      $query->condition($or_condition2);

      // AND user is not admin or anonym.
      $query->condition('u.uid', 1, '>');

      // Adds queryTag to identify this query in a custom module using the
      // hook_query_TAG_alter().
      // The first tag is a general identifier so you can include all the
      // queries that are being processed in this hook_cron().
      // The second tag is unique and only used to make changes to this
      // particular query.
      $query->addTag('inactive_user');
      $query->addTag('notified_user');

      $results = $query->execute();

      $mail_text = $this->getMailText('inactive_user_notify_text');
      $uids = [];
      foreach ($results as $user) {
        if ($user->uid && ($user->access < ($request_time - $notify_time))) {
          $uids[] = $user->uid;
          $this->mail($this->t('[@sitename] Account inactivity', ['@sitename' => $this->siteName]), $mail_text, $notify_time, $user, NULL);
          $this->loggerFactory->get('user')->notice('user %user notified of inactivity', ['%user' => $user->name]);
        }
      }
      if (!empty($uids)) {
        // Update queries return rows updated.
        $query = $this->database->update('users_field_data');
        $query->fields(['notified_user' => 1]);
        $query->condition('uid', $uids, 'in');
        $query->execute();
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function warnedUserBlockTimestamp() {
    $request_time = \Drupal::time()->getRequestTime();
    if (($warn_time = $this->config->get('inactive_user_auto_block_warn')) &&
      ($block_time = $this->config->get('inactive_user_auto_block'))) {
      $query = $this->database->select('users_field_data', 'u');
      $query->fields('u', ['uid', 'name', 'mail', 'created', 'access']);

      // User is logged in and used the site.
      // Last use is earlier than block time + warn time.
      $and_condition1 = $query->andConditionGroup()
        ->condition('u.access', 0, '<>')
        ->condition('u.login', 0, '<>')
        ->condition('u.access', $request_time - $block_time + $warn_time, '<');
      // AND last warn notify is earlier than NOW.
      $sub_and1 = $and_condition1->andConditionGroup()
        ->isNotNull('warned_user_block_timestamp')
        ->condition('warned_user_block_timestamp', $request_time, '<');
      // OR never notified.
      $sub_or1 = $and_condition1->orConditionGroup()
        ->condition($sub_and1)
        ->isNull('warned_user_block_timestamp')
        ->condition('warned_user_block_timestamp', 0);
      // Add or to condition group.
      $and_condition1->condition($sub_or1);

      // OR user never logged in.
      // User created earlier than block time + warn time.
      $and_condition2 = $query->andConditionGroup()
        ->condition('u.login', 0)
        ->condition('u.created', $request_time - $block_time + $warn_time, '<');
      // AND last warn notify is earlier than NOW.
      $sub_and1 = $and_condition2->andConditionGroup()
        ->isNotNull('warned_user_block_timestamp')
        ->condition('warned_user_block_timestamp', $request_time, '<');
      // OR never notified.
      $sub_or1 = $and_condition2->orConditionGroup()
        ->condition($sub_and1)
        ->isNull('warned_user_block_timestamp')
        ->condition('warned_user_block_timestamp', 0);
      // Add or to condition group.
      $and_condition2->condition($sub_or1);

      // Prepare or condition group from above groups.
      $or_condition2 = $query->orConditionGroup()
        ->condition($and_condition1)
        ->condition($and_condition2);
      // Add or condition groups to query.
      $query->condition($or_condition2);

      // AND user status is active.
      $query->condition('u.status', 0, '<>');

      // AND user is not admin or anonym.
      $query->condition('u.uid', 1, '>');

      // Adds queryTag to identify this query in a custom module using the
      // hook_query_TAG_alter().
      // The first tag is a general identifier so you can include all the
      // queries that are being processed in this hook_cron().
      // The second tag is unique and only used to make changes to this
      // particular query.
      $query->addTag('inactive_user');
      $query->addTag('warned_user_block_timestamp');

      $results = $query->execute();

      $uids = [];
      $mail_text = $this->getMailText('inactive_user_block_warn_text');
      foreach ($results as $user) {
        $uids[] = $user->uid;
        $this->mail($this->t('[@sitename] Account inactivity', ['@sitename' => $this->siteName]), $mail_text, $warn_time, $user, NULL);
        $this->loggerFactory->get('user')->notice('user %user warned will be blocked due to inactivity', ['%user' => $user->name]);
      }
    }
    if (!empty($uids)) {
      // Update queries return rows updated.
      $query = $this->database->update('users_field_data');
      $query->fields(['warned_user_block_timestamp' => $request_time + $warn_time]);
      $query->condition('uid', $uids, 'in');
      $query->execute();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function notifyUserBlock() {
    // @todo Fix problem that check again to original code functionality here.
    // Automatically block users.
    if ($block_time = $this->config->get('inactive_user_auto_block')) {
      $request_time = \Drupal::time()->getRequestTime();
      $query = $this->database->select('users_field_data', 'u');
      $query->fields('u', ['uid',
        'name',
        'mail',
        'created',
        'access',
        'warned_user_block_timestamp',
        'notified_admin_block',
      ]);

      // Logged in and used page. Last activity is before block time.
      $and_condition1 = $query->andConditionGroup()
        ->condition('u.access', 0, '<>')
        ->condition('u.login', 0, '<>')
        ->condition('u.access', $request_time - $block_time, '<');
      // OR user never logged in. User created before block time.
      $and_condition2 = $query->andConditionGroup()
        ->condition('u.login', 0)
        ->condition('u.created', $request_time - $block_time, '<');

      // Prepare or condition from abowe.
      $or_condition = $query->orConditionGroup()
        ->condition($and_condition1)
        ->condition($and_condition2);
      // Add or condition to query.
      $query->condition($or_condition);

      // AND don't block user yet if we sent a warning and it hasn't expired.
      $query->isNotNull('warned_user_block_timestamp');
      $query->condition('warned_user_block_timestamp', $request_time, '<');

      // AND status is active.
      $query->condition('u.status', 0, '<>');
      // AND is not admin or anonym user.
      $query->condition('u.uid', 1, '>');

      // Adds queryTag to identify this query in a custom module using the
      // hook_query_TAG_alter().
      // The first tag is a general identifier so you can include all the
      // queries that are being processed in this hook_cron().
      // The second tag is unique and only used to make changes to this
      // particular query.
      $query->addTag('inactive_user');
      $query->addTag('warned_user_block_timestamp');

      $results = $query->execute();

      $inactive_uids = [];
      $notified_uids = [];
      $notify_admin_uids = [];
      $mail_text_user = $this->getMailText('inactive_user_block_notify_text');
      $mail_text_admin = $this->getMailText('block_notify_admin_text');

      $user_list = '';
      foreach ($results as $user) {
        $inactive_uids[] = $user->uid;

        // Notify user.
        if ($this->config->get('inactive_user_notify_block')) {
          $notified_uids[] = $user->uid;
          $this->mail($this->t('[@sitename] Account blocked due to inactivity', ['@sitename' => $this->siteName]), $mail_text_user, $block_time, $user, NULL);
          $this->loggerFactory->get('user')->notice('user %user blocked due to inactivity', ['%user' => $user->name]);
        }

        // Notify admin.
        if ($this->config->get('inactive_user_notify_block_admin')) {
          if (empty($user->notified_admin_block)) {
            $notify_admin_uids[] = $user->uid;
            $user_list .= "$user->name ($user->mail) last active on " . $this->dateFormatter->format($user->access, 'large') . ".\n";
          }
        }

        if (!empty($user_list)) {
          $this->mail($this->t('[@sitename] Blocked users', ['@sitename' => $this->siteName]), $mail_text_admin, $block_time, NULL, $user_list);
        }
      }
      $userStorage =  \Drupal::entityTypeManager()->getStorage('user');
      if (!empty($inactive_uids)) {
        $query = $this->database->update('users_field_data')
          ->fields(['status' => 0])
          ->condition('uid', $inactive_uids, 'in')
          ->execute();
        $userStorage->resetCache($inactive_uids);
      }
      if (!empty($notified_uids)) {
        $query = $this->database->update('users_field_data')
          ->fields(['notified_user_block' => 1])
          ->condition('uid', $notified_uids, 'in')
          ->execute();
        $userStorage->resetCache($notified_uids);
      }
      if (!empty($notify_admin_uids)) {
        $query = $this->database->update('users_field_data')
          ->fields(['notified_admin_block' => 1])
          ->condition('uid', $notify_admin_uids, 'in')
          ->execute();
        $userStorage->resetCache($notify_admin_uids);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function warnedUserDeleteTimestamp() {
    // Warn users when they are about to be deleted.
    if (($warn_time = $this->config->get('inactive_user_auto_delete_warn')) &&
      ($delete_time = $this->config->get('inactive_user_auto_delete'))) {
      $request_time = \Drupal::time()->getRequestTime();
      $query = \Drupal::database()->select('users_field_data', 'u');
      $query->fields('u', ['uid',
        'name',
        'mail',
        'created',
        'access',
        'warned_user_delete_timestamp',
      ]);
      // User is logged in and used the site.
      // Last use is earlier than delete time + warn time.
      $and_condition1 = $query->andConditionGroup()
        ->condition('u.access', 0, '<>')
        ->condition('u.login', 0, '<>')
        ->condition('u.access', $request_time - $delete_time + $warn_time, '<');
      // AND last warn notify is earlier than NOW.
      $sub_and1 = $and_condition1->andConditionGroup()
        ->isNotNull('warned_user_delete_timestamp')
        ->condition('warned_user_delete_timestamp', $request_time, '<');
      // OR never notified.
      $sub_or1 = $and_condition1->orConditionGroup()
        ->condition($sub_and1)
        ->isNull('warned_user_delete_timestamp')
        ->condition('warned_user_delete_timestamp', 0);
      // Add or to condition group.
      $and_condition1->condition($sub_or1);

      // OR user never logged in.
      // User created earlier than delete time + warn time.
      $and_condition2 = $query->andConditionGroup()
        ->condition('u.login', 0)
        ->condition('u.created', $request_time - $delete_time + $warn_time, '<');
      // AND last warn notify is earlier than NOW.
      $sub_and1 = $and_condition2->andConditionGroup()
        ->isNotNull('warned_user_delete_timestamp')
        ->condition('warned_user_delete_timestamp', $request_time, '<');
      // OR never notified.
      $sub_or1 = $and_condition2->orConditionGroup()
        ->condition($sub_and1)
        ->isNull('warned_user_delete_timestamp')
        ->condition('warned_user_delete_timestamp', 0);
      // Add or to condition group.
      $and_condition2->condition($sub_or1);

      // Prepare or condition group from above groups.
      $or_condition = $query->orConditionGroup()
        ->condition($and_condition1)
        ->condition($and_condition2);

      // Add or condition group to query.
      $query->condition($or_condition);

      // AND is not admin or anonym user.
      $query->condition('u.uid', 1, '>');

      // Adds queryTag to identify this query in a custom module using the
      // hook_query_TAG_alter().
      // The first tag is a general identifier so you can include all the
      // queries that are being processed in this hook_cron().
      // The second tag is unique and only used to make changes to this
      // particular query.
      $query->addTag('inactive_user');
      $query->addTag('warn_users_deleted');

      $results = $query->execute();

      $mail_text = $this->getMailText('inactive_user_delete_warn_text');
      foreach ($results as $user) {
        if (empty($user->warned_user_delete_timestamp) &&
          ($user->access < ($request_time - $warn_time))) {
          $protected = ($this->config->get('inactive_user_preserve_content') && $this->inactiveUserWithContent($user->uid));

          // The db_update function returns the number of rows altered.
          $query = $this->database->update('users_field_data')
            ->fields([
              'warned_user_delete_timestamp' => $request_time + $warn_time,
              'protected' => $protected ? 1 : 0,
            ])
            ->condition('uid', $user->uid)
            ->execute();

          if (!$protected) {
            $this->mail($this->t('[@sitename] Account inactivity', ['@sitename' => $this->siteName]), $mail_text, $warn_time, $user, NULL);
            $this->loggerFactory->get('user')->notice('user %user warned will be deleted due to inactivity', ['%user' => $user->mail]);
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function autoUserDelete() {
    // Automatically delete users.
    if ($delete_time = $this->config->get('inactive_user_auto_delete')) {
      $request_time = \Drupal::time()->getRequestTime();
      $query = $this->database->select('users_field_data', 'u');
      $query->fields('u', ['uid',
        'name',
        'mail',
        'created',
        'access',
        'warned_user_delete_timestamp',
        'protected',
      ]);
      // Logged in and used page. Last activity is before delete time.
      $and_condition1 = $query->andConditionGroup()
        ->condition('u.access', 0, '<>')
        ->condition('u.login', 0, '<>')
        ->condition('u.access', $request_time - $delete_time, '<');

      // OR user never logged in. User created before delete time.
      $and_condition2 = $query->andConditionGroup()
        ->condition('u.login', 0)
        ->condition('u.created', $request_time - $delete_time, '<');

      $or_condition = $query->orConditionGroup()
        ->condition($and_condition1)
        ->condition($and_condition2);
      $query->condition($or_condition);

      // AND don't block user yet if we sent a warning and it hasn't expired.
      $query->isNotNull('warned_user_delete_timestamp');
      $query->condition('warned_user_delete_timestamp', $request_time, '<');

      // User is not protected.
      $query->condition('protected', 0);

      // AND is not admin or anonym user.
      $query->condition('u.uid', 1, '>');

      // Adds queryTag to identify this query in a custom module using the
      // hook_query_TAG_alter().
      // The first tag is a general identifier so you can include all the
      // queries that are being processed in this hook_cron().
      // The second tag is unique and only used to make changes to this
      // particular query.
      $query->addTag('inactive_user');
      $query->addTag('delete_users');

      $results = $query->execute();

      $mail_text = $this->getMailText('inactive_user_delete_notify_text');
      $user_list = '';
      foreach ($results as $user) {
        if ($this->config->get('inactive_user_auto_delete_warn') > 0) {

          $protect = $this->config->get('inactive_user_preserve_content') ?: 1;
          $is_protected = ($protect && $this->inactiveUserWithContent($user->uid));
          if ($is_protected) {
            // This is a protected user, mark as such.
            $query = \Drupal::database()->update('users_field_data')
              ->fields(['protected' => $is_protected])
              ->condition('uid', $user->uid)
              ->execute();
          }
          else {
            // Delete the user.
            // Not using user_delete() to send custom emails and watchdog.
            $account = $this->serviceContainer->get('entity_type.manager')->getStorage('user')->load($user->uid);
            $account->delete();

            if ($this->config->get('inactive_user_notify_delete')) {
              $this->mail($this->t('[@sitename] Account removed', ['@sitename' => $this->siteName]), $mail_text, $delete_time, $user, NULL);
            }
            if ($this->config->get('inactive_user_notify_delete_admin')) {
              $user_list .= "$user->name ($user->mail) last active on " . $this->dateFormatter->format($user->access, 'large') . ".\n";
            }
            $this->loggerFactory->get('user')->notice('user %user deleted due to inactivity', ['%user' => $user->name]);
          }
        }
      }
      if (!empty($user_list)) {
        $this->mail($this->t('[@sitename] Deleted accounts', ['@sitename' => $this->siteName]), $this->getMailText('delete_notify_admin_text'), $delete_time, NULL, $user_list);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function inactiveUserAdminMail() {
    if ($adresses = $this->config->get('inactive_user_admin_email')) {
      return $adresses;
    }
    $admin = $this->serviceContainer->get('entity_type.manager')->getStorage('user')->load(1);
    return $admin->getEmail();
  }

  /**
   * {@inheritdoc}
   */
  public function getMailText($key) {
    $mail_text = $this->mailText($key);
    if ($text = $this->config->get($key)) {
      $mail_text = $text;
    }

    return $mail_text;
  }

  /**
   * {@inheritdoc}
   */
  public function mailText($key) {
    switch ($key) {
      case 'notify_text':
        return $this->t("Hello %username,\n\n  We haven't seen you at %sitename since %lastaccess, and we miss you!  Please come back and visit us soon at %siteurl.\n\nSincerely,\n  %sitename team");

      case 'notify_admin_text':
        return $this->t("Hello,\n\n  This automatic notification is to inform you that the following users haven't been seen on %sitename for more than %period:\n\n%userlist");

      case 'block_warn_text':
        return $this->t("Hello %username,\n\n  We haven't seen you at %sitename since %lastaccess, and we miss you!  This automatic message is to warn you that your account will be disabled in %period unless you come back and visit us before that time.\n\n  Please visit us at %siteurl.\n\nSincerely,\n  %sitename team");

      case 'block_notify_text':
        return $this->t("Hello %username,\n\n  This automatic message is to notify you that your account on %sitename has been automatically disabled due to no activity for more than %period.\n\n  Please visit us at %siteurl to have your account re-enabled.\n\nSincerely,\n  %sitename team");

      case 'block_notify_admin_text':
        return $this->t("Hello,\n\n  This automatic notification is to inform you that the following users have been automatically blocked due to inactivity on %sitename for more than %period:\n\n%userlist");

      case 'delete_warn_text':
        return $this->t("Hello %username,\n\n  We haven't seen you at %sitename since %lastaccess, and we miss you!  This automatic message is to warn you that your account will be completely removed in %period unless you come back and visit us before that time.\n\n  Please visit us at %siteurl.\n\nSincerely,\n  %sitename team");

      case 'delete_notify_text':
        return $this->t("Hello %username,\n\n  This automatic message is to notify you that your account on %sitename has been automatically removed due to no activity for more than %period.\n\n  Please visit us at %siteurl if you would like to create a new account.\n\nSincerely,\n  %sitename team");

      case 'delete_notify_admin_text':
        return $this->t("Hello,\n\n  This automatic notification is to inform you that the following users have been automatically deleted due to inactivity on %sitename for more than %period:\n\n%userlist");
    }
  }

  /**
   * {@inheritdoc}
   */
  public function mail($subject,
    $message,
    $period,
    $user = NULL,
    $user_list = NULL) {
    $site_name = $this->siteName;
    if (empty($site_name)) {
      $site_name = 'Drupal';
    }

    $base_url = $this->serviceContainer->get('request_stack')->getCurrentRequest()->getHost();
    $url = Url::fromUserInput("/");
    $link = Link::fromTextAndUrl($base_url, $url);

    $interval = $this->dateFormatter->formatInterval($period);

    if ($user_list) {
      $to = $this->inactiveUserAdminMail();
      $variables = [
        '%period' => $interval,
        '%sitename' => $site_name,
        '%siteurl' => $link->toString(),
        "%userlist" => $user_list,
      ];
    }
    elseif (isset($user->uid)) {
      $to = $user->mail;
      $access = $this->t('never');
      if (!empty($user->access)) {
        $access = $this->dateFormatter->format($user->access, 'short');
      }
      $variables = [
        '%username' => $user->name,
        '%useremail' => $user->mail,
        '%lastaccess' => $access,
        '%period' => $interval,
        '%sitename' => $site_name,
        '%siteurl' => $link->toString(),
      ];
    }
    if (isset($to)) {

      $from = $this->configFactory->get('system.site')->get('mail');
      if (empty($from)) {
        $from = ini_get('sendmail_from');
      }

      $headers = [
        'Reply-to' => $from,
        'Return-path' => "<$from>",
        'Errors-to' => $from,
      ];
      $recipients = explode(',', $to);
      foreach ($recipients as $recipient) {
        $recipient = trim($recipient);
        $params = [
          'subject' => $subject,
          'message' => strtr($message, $variables),
          'headers' => $headers,
          'from' => $from,
        ];
        $language = $this->serviceContainer->get('language.default')->get()->getId();
        if ($user = user_load_by_mail($recipient)) {
          $language = $user->getPreferredLangcode();
        }
        $this->serviceContainer->get('plugin.manager.mail')->mail('inactive_user', 'inactive_user_notice', $recipient, $language, $params);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function inactiveUserWithContent($uid) {
    $user_has_nodes = 0;
    $user_has_comments = 0;
    $other = 0;
    $module_handler = $this->serviceContainer->get('module_handler');
    if ($module_handler->moduleExists('node')) {
      $user_has_nodes = $this->database->select('node_field_data', 'n')
        ->fields('n', ['uid'])
        ->condition('n.uid', $uid)
        ->countQuery()
        ->execute()
        ->fetchField();
    }
    if ($module_handler->moduleExists('comment')) {
      $user_has_comments = $this->database->select('comment_field_data', 'c')
        ->fields('c', ['uid'])
        ->condition('c.uid', $uid)
        ->countQuery()
        ->execute()
        ->fetchField();
    }

    if ($user_has_nodes + $user_has_comments == 0) {
      // Define hook_inactive_user_with_content_alter(&$other, $uid) hook.
      $this->serviceContainer->get('module_handler')->alter('inactive_user_with_content', $other, $uid);
    }

    return ($user_has_nodes + $user_has_comments + $other) > 0;
  }

  /**
   * Helper function to prepare site name variable.
   */
  protected function getSiteName() {
    $this->siteName = $this->configFactory->get('system.site')->get('name');
    if (empty($this->siteName)) {
      $this->siteName = 'Drupal';
    }
  }

  /**
   * Delete user function.
   *
   * @param object $user
   *   The user object from query.
   */
  protected function deleteUser($user) {
    $this->serviceContainer->get('session_manager')->delete($user->id());
    \Drupal::database()->delete('users')
      ->condition('uid', $user->uid)
      ->execute();
    \Drupal::database()->delete('users_field_data')
      ->condition('uid', $user->uid)
      ->execute();
    \Drupal::database()->delete('user__roles')
      ->condition('uid', $user->uid)
      ->execute();
    \Drupal::database()->delete('inactive_users')
      ->condition('uid', $user->uid)
      ->execute();

    // @todo Fix problem that user delete is not invoked here.
  }

}
