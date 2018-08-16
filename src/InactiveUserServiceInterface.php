<?php

namespace Drupal\inactive_user;

/**
 * Interface InactiveUserServiceInterface.
 */
interface InactiveUserServiceInterface {

  /**
   * Run cron function for execute inactive user processes.
   */
  public function runCron();

  /**
   * Reset notifications if recent user activity.
   */
  public function resetAdminNotifications();

  /**
   * Notify administrator of inactive user accounts.
   */
  public function notifyAdmin();

  /**
   * Notify users that their account has been inactive.
   */
  public function notifyUser();

  /**
   * Warn users when they are about to be blocked.
   *
   * This query asks for all users who are not user 1, that have logged in
   * at least once, but not since the request_time minus the interval
   * represented by the block time plus the warning lead time or
   * all users who haven't logged in but were created since the
   * request time minus the interval represented by the block time
   * plus the warning lead time.
   */
  public function warnedUserBlockTimestamp();

  /**
   * Automatically block users.
   */
  public function notifyUserBlock();

  /**
   * Warn users when they are about to be deleted.
   */
  public function warnedUserDeleteTimestamp();

  /**
   * Automatically delete users.
   */
  public function autoUserDelete();

  /**
   * Get administrator e-mail address(es).
   */
  public function inactiveUserAdminMail();

  /**
   * Get mail text from config.
   *
   * @param string $key
   *   The mail text key.
   */
  public function getMailText($key);

  /**
   * Some default e-mail notification strings.
   *
   * @param string $key
   *   The mail text key.
   */
  public function mailText($key);

  /**
   * Wrapper for user_mail.
   *
   * @param string $subject
   *   The mail subject text.
   * @param string $message
   *   The message text.
   * @param int $period
   *   The period when user was inactive.
   * @param object $user
   *   The user object from query.
   * @param type $user_list
   *   The user list to sending message.
   */
  public function mail($subject, $message, $period, $user, $user_list);
  
  /**
   * Returns TRUE if the user has ever created a node or a comment.
   *
   * The settings of inactive_user.module allow to protect such
   * users from deletion.
   *
   * @param int $uid
   *   The user id.
   */
  public function inactiveUserWithContent($uid);

}
