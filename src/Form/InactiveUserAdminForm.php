<?php

namespace Drupal\inactive_user\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\inactive_user\InactiveUserServiceInterface;

/**
 * Class InactiveUserAdminForm.
 */
class InactiveUserAdminForm extends ConfigFormBase {

  /**
   * Drupal\inactive_user\InactiveUserServiceInterface.
   *
   * @var Drupal\inactive_user\InactiveUserServiceInterface
   */
  protected $datetimeTime;

  /**
   * Drupal\Component\Datetime\TimeInterface definition.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $inactiveUserNotify;

  /**
   * Constructs a new InactiveUserAdminForm object.
   */
  public function __construct(
  ConfigFactoryInterface $config_factory,
    TimeInterface $datetime_time,
    InactiveUserServiceInterface $inactive_user_notify
  ) {
    parent::__construct($config_factory);
    $this->datetimeTime = $datetime_time;
    $this->inactiveUserNotify = $inactive_user_notify;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'), $container->get('datetime.time'), $container->get('inactive_user.notify')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'inactive_user.inactiveuseradmin',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'inactive_user_admin_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form,
    FormStateInterface $form_state) {
    $mail_variables = ' %username, %useremail, %lastaccess, %period, %sitename, %siteurl';

    // Set administrator e-mail.
    $config = $this->config('inactive_user.inactiveuseradmin');
    $form['inactive_user_admin_email'] = [
      '#type' => 'details',
      '#title' => $this->t('Administrator e-mail'),
      '#open' => TRUE,
    ];

    $form['inactive_user_admin_email']['inactive_user_admin_email'] = [
      '#type' => 'textfield',
      '#title' => $this->t('E-mail addresses'),
      '#default_value' => $this->inactiveUserNotify->inactiveUserAdminMail(),
      '#description' => $this->t('Supply a comma-separated list of e-mail addresses that will receive administrator alerts. Spaces between addresses are allowed.'),
      '#maxlength' => 256,
      '#required' => TRUE,
    ];

    // Inactive user notification.
    $form['inactive_user_notification'] = [
      '#type' => 'details',
      '#title' => $this->t('Inactive user notification'),
      '#open' => FALSE,
    ];
    $form['inactive_user_notification']['inactive_user_notify_admin'] = [
      '#type' => 'select',
      '#title' => $this->t("Notify administrator when a user hasn't logged in for more than"),
      '#default_value' => $config->get('inactive_user_notify_admin'),
      '#options' => $this->periodOptionList(),
      '#description' => $this->t("Generate an email to notify the site administrator that a user account hasn't been used for longer than the specified amount of time.  Requires crontab."),
    ];
    $form['inactive_user_notification']['inactive_user_notify'] = [
      '#type' => 'select',
      '#title' => $this->t("Notify users when they haven't logged in for more than"),
      '#default_value' => $config->get('inactive_user_notify'),
      '#options' => $this->periodOptionList(),
      '#description' => $this->t("Generate an email to notify users when they haven't used their account for longer than the specified amount of time.  Requires crontab."),
    ];

    $notify_text = $config->get('inactive_user_notify_text');
    if (empty($notify_text)) {
      $notify_text = $this->inactiveUserNotify->getMailText('notify_text');
    }
    $form['inactive_user_notification']['inactive_user_notify_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body of user notification e-mail'),
      '#default_value' => $notify_text,
      '#cols' => 70,
      '#rows' => 10,
      '#description' => $this->t('Customize the body of the notification e-mail sent to the user. Available variables are:') . $mail_variables,
      '#required' => TRUE,
    ];

    // Automatically block inactive users.
    $form['block_inactive_user'] = [
      '#type' => 'details',
      '#title' => $this->t('Automatically block inactive users'),
      '#open' => FALSE,
    ];
    $form['block_inactive_user']['inactive_user_auto_block_warn'] = [
      '#type' => 'select',
      '#title' => $this->t('Warn users before they are blocked'),
      '#default_value' => $config->get('inactive_user_auto_block_warn'),
      '#options' => $this->warnPeriodOptionList(),
      '#description' => $this->t('Generate an email to notify a user that his/her account is about to be blocked.'),
    ];

    $warn_text = $config->get('inactive_user_block_warn_text');
    if (empty($warn_text)) {
      $warn_text = $this->inactiveUserNotify->getMailText('block_warn_text');
    }
    $form['block_inactive_user']['inactive_user_block_warn_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body of user warning e-mail'),
      '#default_value' => $warn_text,
      '#cols' => 70,
      '#rows' => 10,
      '#description' => $this->t('Customize the body of the notification e-mail sent to the user when their account is about to be blocked. Available variables are:') . $mail_variables,
      '#required' => TRUE,
    ];
    $form['block_inactive_user']['inactive_user_auto_block'] = [
      '#type' => 'select',
      '#prefix' => '<div><hr></div>',
      '#title' => $this->t("Block users who haven't logged in for more than"),
      '#default_value' => $config->get('inactive_user_auto_block'),
      '#options' => $this->periodOptionList(),
      '#description' => $this->t("Automatically block user accounts that haven't been used in the specified amount of time.  Requires crontab."),
    ];
    $form['block_inactive_user']['inactive_user_notify_block'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Notify user'),
      '#default_value' => $config->get('inactive_user_notify_block'),
      '#description' => $this->t('Generate an email to notify a user that his/her account has been automatically blocked.'),
    ];

    $block_notify_text = $config->get('inactive_user_block_notify_text');
    if (empty($block_notify_text)) {
      $block_notify_text = $this->inactiveUserNotify->getMailText('block_notify_text');
    }
    $form['block_inactive_user']['inactive_user_block_notify_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body of blocked user account e-mail'),
      '#default_value' => $block_notify_text,
      '#cols' => 70,
      '#rows' => 10,
      '#description' => $this->t('Customize the body of the notification e-mail sent to the user when their account has been blocked. Available variables are:') . $mail_variables,
      '#required' => TRUE,
    ];
    $form['block_inactive_user']['inactive_user_notify_block_admin'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Notify administrator'),
      '#default_value' => $config->get('inactive_user_notify_block_admin'),
      '#description' => $this->t('Generate an email to notify the site administrator when a user is automatically blocked.'),
    ];

    // Automatically delete inactive users.
    $form['delete_inactive_user'] = [
      '#type' => 'details',
      '#title' => $this->t('Automatically delete inactive users'),
      '#open' => FALSE,
    ];
    $form['delete_inactive_user']['inactive_user_auto_delete_warn'] = [
      '#type' => 'select',
      '#title' => $this->t('Warn users before they are deleted'),
      '#default_value' => $config->get('inactive_user_auto_delete_warn'),
      '#options' => $this->warnPeriodOptionList(),
      '#description' => $this->t('Generate an email to notify a user that his/her account is about to be deleted.'),
    ];

    $delete_warn_text = $config->get('inactive_user_delete_warn_text');
    if (empty($delete_warn_text)) {
      $delete_warn_text = $this->inactiveUserNotify->getMailText('delete_warn_text');
    }
    $form['delete_inactive_user']['inactive_user_delete_warn_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body of user warning e-mail'),
      '#default_value' => $delete_warn_text,
      '#cols' => 70,
      '#rows' => 10,
      '#description' => $this->t('Customize the body of the notification e-mail sent to the user when their account is about to be deleted. Available variables are:') . $mail_variables,
      '#required' => TRUE,
    ];
    $form['delete_inactive_user']['inactive_user_auto_delete'] = [
      '#type' => 'select',
      '#prefix' => '<div><hr></div>',
      '#title' => $this->t("Delete users who haven't logged in for more than"),
      '#default_value' => $config->get('inactive_user_auto_delete'),
      '#options' => $this->periodOptionList(),
      '#description' => $this->t("Automatically delete user accounts that haven't been used in the specified amount of time.  Warning, user accounts are permanently deleted, with no ability to undo the action!  Requires crontab."),
    ];
    $form['delete_inactive_user']['inactive_user_preserve_content'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Preserve users that own site content'),
      '#default_value' => $config->get('inactive_user_preserve_content'),
      '#description' => $this->t('Select this option to never delete users that own site content.  If you delete a user that owns content on the site, such as a user that created a node or left a comment, the content will no longer be available via the normal Drupal user interface.  That is, if a user creates a node or leaves a comment, then the user is deleted, the node and/or comment will no longer be accesible even though it will still be in the database.'),
    ];
    $form['delete_inactive_user']['inactive_user_notify_delete'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Notify user'),
      '#default_value' => $config->get('inactive_user_notify_delete'),
      '#description' => $this->t('Generate an email to notify a user that his/her account has been automatically deleted.'),
    ];

    $delete_notify_text = $config->get('inactive_user_delete_notify_text');
    if (empty($delete_notify_text)) {
      $delete_notify_text = $this->inactiveUserNotify->getMailText('delete_notify_text');
    }
    $form['delete_inactive_user']['inactive_user_delete_notify_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body of deleted user account e-mail'),
      '#default_value' => $delete_notify_text,
      '#cols' => 70,
      '#rows' => 10,
      '#description' => $this->t('Customize the body of the notification e-mail sent to the user when their account has been deleted. Available variables are:') . $mail_variables,
      '#required' => TRUE,
    ];
    $form['delete_inactive_user']['inactive_user_notify_delete_admin'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Notify administrator'),
      '#default_value' => $config->get('inactive_user_notify_delete_admin'),
      '#description' => $this->t('Generate an email to notify the site administrator when a user is automatically deleted.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form,
    FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $valid_email = $form_state->getValue('inactive_user_admin_email');
    $mails = explode(',', $valid_email);
    $count = 0;
    foreach ($mails as $mail) {
      if ($mail && !valid_email_address(trim($mail))) {
        $invalid[] = $mail;
        $count++;
      }
    }
    if ($count == 1) {
      $form_state->setError(['inactive_user_admin_email'], $this->t('%mail is not a valid e-mail address', array('%mail' => $invalid[0])));
    }
    elseif ($count > 1) {
      $form_state->setError('inactive_user_admin_email', $this->t('The following e-mail addresses are invalid: %mail', array('%mail' => implode(', ', $invalid))));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form,
    FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $elements = [
      'inactive_user_admin_email',
      'inactive_user_notify_admin',
      'inactive_user_notify',
      'inactive_user_notify_text',
      'inactive_user_auto_block_warn',
      'inactive_user_block_warn_text',
      'inactive_user_auto_block',
      'inactive_user_notify_block',
      'inactive_user_block_notify_text',
      'inactive_user_notify_block_admin',
      'inactive_user_auto_delete_warn',
      'inactive_user_delete_warn_text',
      'inactive_user_auto_delete',
      'inactive_user_preserve_content',
      'inactive_user_notify_delete',
      'inactive_user_delete_notify_text',
      'inactive_user_notify_delete_admin',
    ];
    foreach ($elements as $element) {
      $this->config('inactive_user.inactiveuseradmin')
        ->set($element, $form_state->getValue($element))
        ->save();
    }
  }

  /**
   * The period option list.
   *
   * @return array periodOptionList
   */
  protected function periodOptionList() {
    return [
      0 => 'disabled',
      ONE_WEEK => $this->t('1 week'),
      TWO_WEEKS => $this->t('2 weeks'),
      THRE_WEEKS => $this->t('3 weeks'),
      FOUR_WEEKS => $this->t('4 weeks'),
      ONE_MONTH => $this->t('1 month'),
      THRE_MONTHS => $this->t('3 months'),
      SIX_MONTHS => $this->t('6 months'),
      NINE_MONTHS => $this->t('9 months'),
      ONE_YEAR => $this->t('1 year'),
      ONE_AND_HALF_YEARS => $this->t('1.5 years'),
      TWO_YEARS => $this->t('2 years'),
    ];
  }

  /**
   * The warn period option list.
   *
   * @return array warnPeriodOptionList
   */
  protected function warnPeriodOptionList() {
    return [
      0 => $this->t('Disabled'),
      ONE_DAY => $this->t('1 day'),
      TWO_DAYS => $this->t('2 days'),
      THRE_DAYS => $this->t('3 days'),
      ONE_WEEK => $this->t('7 days'),
      TWO_WEEKS => $this->t('14 days'),
      THRE_WEEKS => $this->t('21 days'),
      ONE_MONTH => $this->t('30 days'),
    ];
  }

}
