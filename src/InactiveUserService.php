<?php

namespace Drupal\inactive_user;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   * @var Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Drupal\Core\Logger\LoggerChannelFactoryInterface definition.
   *
   * @var Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Inactive user config.
   *
   * @var Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * The site name.
   *
   * @var string
   */
  protected $siteName;

  /**
   * State service.
   *
   * @var Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructs a SessionManager object.
   *
   * @var \Drupal\Core\StringTranslation\SessionManagerInterface
   */
  protected $sessionManager;

  /**
   * Constructs a EntityTypeManager object.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityManager;

  /**
   * {@inheritdoc}
   */
  public function __construct() {

  }

  /**
   * Configure service dependencies.
   *
   * @param ContainerInterface $container
   *   The Container object.
   */
  public function configure(ContainerInterface $container) {
    $this->database = $container->get('database');
    $this->configFactory = $container->get('config.factory');
    $this->dateFormatter = $container->get('date.formatter');
    $this->loggerFactory = $container->get('logger.factory');
    $this->config = $this->configFactory->getEditable('inactive_user.inactiveuseradmin');
    $this->getSiteName();
    $this->state = $container->get('state');
    $this->sessionManager = $container->get('session_manager');
    $this->entityManager = $container->get('entity_type.manager');
  }

  /**
   * {@inheritdoc}
   */
  public function runCron() {
    if ((REQUEST_TIME - $this->state->get('inactive_user_timestamp', 0)) >= DAY_MINUS_FIVE_MINUTES) {
      $this->state->set('inactive_user_timestamp', REQUEST_TIME);

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
    $query->condition('u.uid', [0, 1], 'not in');
    $query->condition('u.notified_admin', 1);
    $query->condition('u.access', REQUEST_TIME - ONE_WEEK, '>');
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
      $query = $this->database->select('users_field_data', 'u');
      $query->fields('u', ['uid', 'name', 'mail', 'access', 'created']);
      $and_condition1 = $query->andConditionGroup()
        ->condition('u.access', 0, '<>')
        ->condition('u.login', 0, '<>')
        ->condition('u.access', REQUEST_TIME - $notify_time, '<');
      $and_condition2 = $query->andConditionGroup()
        ->condition('u.login', 0)
        ->condition('u.created', REQUEST_TIME - $notify_time, '<');
      $query->orConditionGroup()
        ->condition($and_condition1)
        ->condition($and_condition2);
      // Has not the admin been notified.
      $query->condition('u.notified_admin', 0);
      $query->condition('u.uid', [0, 1], 'not in');

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
      foreach ($results as $user) {
        $uids = [];
        if ($user->uid && ($user->access < (REQUEST_TIME - $notify_time))) {
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
      $query = $this->database->select('users_field_data', 'u');
      $query->fields('u', ['uid', 'name', 'mail', 'access', 'created']);
      $and_condition1 = $query->andConditionGroup()
        ->condition('u.access', 0, '<>')
        ->condition('u.login', 0, '<>')
        ->condition('u.access', REQUEST_TIME - $notify_time, '<');
      $and_condition2 = $query->andConditionGroup()
        ->condition('u.login', 0)
        ->condition('u.created', REQUEST_TIME - $notify_time, '<');
      $query->orConditionGroup()
        ->condition($and_condition1)
        ->condition($and_condition2);
      // Has not the admin been notified.
      $query->condition('u.notified_user', 0);
      $query->condition('u.uid', [0, 1], 'not in');

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
      foreach ($results as $user) {
        $uids = [];
        if ($user->uid && ($user->access < (REQUEST_TIME - $notify_time))) {
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
    if (($warn_time = $this->config->get('inactive_user_auto_block_warn')) &&
      ($block_time = $this->config->get('inactive_user_auto_block'))) {
      $query = $this->database->select('users_field_data', 'u');
      $query->fields('u', ['uid', 'name', 'mail', 'created', 'access']);
      $and_condition1 = $query->andConditionGroup()
        ->condition('u.access', 0, '<>')
        ->condition('u.login', 0, '<>')
        ->condition('u.access', REQUEST_TIME - $block_time + $warn_time, '<');
      $and_condition2 = $query->andConditionGroup()
        ->condition('u.login', 0)
        ->condition('u.created', REQUEST_TIME - $block_time + $warn_time, '<');
      $query->orConditionGroup()
        ->condition($and_condition1)
        ->condition($and_condition2);
      $query->condition('u.warned_user_block_timestamp', 0, '>');
      $query->condition('u.status', 0, '<>');
      $query->condition('u.uid', [0, 1], 'not in');

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
        $uids[] = $user->id();
        $this->mail($this->t('[@sitename] Account inactivity', ['@sitename' => $this->siteName]), $mail_text, $warn_time, $user, NULL);
        $this->loggerFactory->get('user')->notice('user %user warned will be blocked due to inactivity', ['%user' => $user->name]);
      }
    }
    if (!empty($uids)) {
      // Update queries return rows updated.
      $query = $this->database->update('users_field_data');
      $query->fields(['warned_user_block_timestamp' => REQUEST_TIME + $warn_time]);
      $query->condition('uid', $uids, 'in');
      $query->execute();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function notifyUserBlock() {
    // TODO: check again to original code functionality.
    // Automatically block users.
    if ($block_time = $this->config->get('inactive_user_auto_block')) {
      $query = $this->database->select('users_field_data', 'u');
      $query->fields('u', ['uid',
        'name',
        'mail',
        'created',
        'access',
        'warned_user_block_timestamp',
        'notified_admin_block',
      ]);
      $and_condition1 = $query->andConditionGroup()
        ->condition('u.access', 0, '<>')
        ->condition('u.login', 0, '<>')
        ->condition('u.access', REQUEST_TIME - $block_time, '<');
      $and_condition2 = $query->andConditionGroup()
        ->condition('u.login', 0)
        ->condition('u.created', REQUEST_TIME - $block_time, '<');
      $query->orConditionGroup()
        ->condition($and_condition1)
        ->condition($and_condition2);
      $query->condition('u.notified_user_block', 0);
      $query->condition('u.status', 0, '<>');
      $query->condition('u.uid', [0, 1], 'not in');

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
        // Don't block user yet if we sent a warning and it hasn't expired.
        if ($user->uid &&
          $user->warned_user_block_timestamp > REQUEST_TIME &&
          ($user->access < (REQUEST_TIME - $block_time))) {

          $inactive_uids[] = $user->id();

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
        }
        if (!empty($user_list)) {
          $this->mail($this->t('[@sitename] Blocked users', ['@sitename' => $this->siteName]), $mail_text_admin, $block_time, NULL, $user_list);
        }
      }
      if (!empty($inactive_uids)) {
        $query = $this->database->update('users_field_data')
          ->fields(['status' => 0])
          ->condition('uid', $inactive_uids, 'in')
          ->execute();
      }
      if (!empty($notified_uids)) {
        $query = $this->database->update('users_field_data')
          ->fields(['notified_user_block' => 1])
          ->condition('uid', $notified_uids, 'in')
          ->execute();
      }
      if (!empty($notify_admin_uids)) {
        $query = $this->database->update('users_field_data')
          ->fields(['notified_admin_block' => 1])
          ->condition('uid', $notify_admin_uids, 'in')
          ->execute();
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
      $query = db_select('users_field_data', 'u');
      $query->fields('u', ['uid',
        'name',
        'mail',
        'created',
        'access',
        'warned_user_delete_timestamp',
      ]);
      $and_condition1 = $query->andConditionGroup()
        ->condition('u.access', 0, '<>')
        ->condition('u.login', 0, '<>')
        ->condition('u.access', REQUEST_TIME - $delete_time + $warn_time, '<');
      $and_condition2 = $query->andConditionGroup()
        ->condition('u.login', 0)
        ->condition('u.created', REQUEST_TIME - $delete_time + $warn_time, '<');
      $query->orConditionGroup()
        ->condition($and_condition1)
        ->condition($and_condition2);
      $query->condition('u.uid', [0, 1], 'not in');

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
          ($user->access < (REQUEST_TIME - $warn_time))) {
          $protected = ($this->config->get('inactive_user_preserve_content') && $this->inactiveUserWithContent($user->uid));

          // The db_update function returns the number of rows altered.
          $query = $this->database->update('users_field_data')
            ->fields([
              'warned_user_delete_timestamp' => REQUEST_TIME + $warn_time,
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
      $query = $this->database->select('users_field_data', 'u');
      $query->fields('u', ['uid',
        'name',
        'mail',
        'created',
        'access',
        'warned_user_delete_timestamp',
        'protected',
      ]);
      $and_condition1 = $query->andConditionGroup()
        ->condition('u.access', 0, '<>')
        ->condition('u.login', 0, '<>')
        ->condition('u.access', REQUEST_TIME - $delete_time, '<');
      $and_condition2 = $query->andConditionGroup()
        ->condition('u.login', 0)
        ->condition('u.created', REQUEST_TIME - $delete_time, '<');
      $query->orConditionGroup()
        ->condition($and_condition1)
        ->condition($and_condition2);
      $query->condition('u.uid', [0, 1], 'not in');

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
        $deleteable_user_results = ($user->warned_user_delete_timestamp < REQUEST_TIME && $user->protected != 1);
        if ($user->uid &&
          ((($this->config->get('inactive_user_auto_delete_warn') > 0) && !$deleteable_user_results) ||
          (!$this->config->get('inactive_user_auto_delete_warn'))) && ($user->access < (REQUEST_TIME - $delete_time))) {

          $protect = $this->config->get('inactive_user_preserve_content') ?: 1;
          $is_protected = ($protect && $this->inactiveUserWithContent($user->uid));
          if ($is_protected) {
            // This is a protected user, mark as such.
            $query = db_update('users_field_data')
              ->fields(['protected' => $is_protected])
              ->condition('uid', $user->uid)
              ->execute();
          }
          else {
            // Delete the user.
            // Not using user_delete() to send custom emails and watchdog.
            // $array = (array) $user;
            // TODO: look into which methode using for User entity deletion.
            // Prepare the userDelete function.
            $account = $this->entityManager->getStorage('user')->load($user->uid);
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
    $admin = $this->entityManager->getStorage('user')->load(1);
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
    $url = Url::fromUserInput($base_url);
    $link = Link::fromTextAndUrl($base_url, $url);

    $date_formatter = $this->serviceContainer->get('date.formatter');
    $interval = $date_formatter->formatInterval($period);

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
        $access = $date_formatter->format($user->access, 'short');
      }
      $variables = [
        '%username' => $user->name,
        '%useremail' => $user->mail,
        '%lastaccess' => $access,
        '%period' => $interval,
        '%sitename' => $site_name,
        '%siteurl' => $link,
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
        ];
        $language = $this->serviceContainer->get('language.default')->get()->getId();
        if ($user = user_load_by_mail($recipient)) {
          $language = $user->getPreferredLangcode();
        }
        drupal_mail('inactive_user', 'inactive_user_notice', $recipient, $language, $params, $from, TRUE);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function inactiveUserWithContent($uid) {
    $user_has_nodes = $this->database->select('node_field_data', 'n')
      ->fields('n', ['uid'])
      ->condition('n.uid', $uid)
      ->countQuery()
      ->execute()
      ->fetchField();
    $user_has_comments = $this->database->select('comment_field_data', 'c')
      ->fields('c', ['uid'])
      ->condition('c.uid', $uid)
      ->countQuery()
      ->execute()
      ->fetchField();

    return ($user_has_nodes + $user_has_comments) > 0;
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
    $this->sessionManager->delete($user->id());
    db_delete('users')
      ->condition('uid', $user->uid)
      ->execute();
    db_delete('users_field_data')
      ->condition('uid', $user->uid)
      ->execute();
    db_delete('user__roles')
      ->condition('uid', $user->uid)
      ->execute();
    db_delete('inactive_users')
      ->condition('uid', $user->uid)
      ->execute();

    // TODO: invoke user delete.
  }

}
