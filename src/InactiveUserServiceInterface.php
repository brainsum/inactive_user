<?php

namespace Drupal\inactive_user;

/**
 * Interface InactiveUserServiceInterface.
 */
interface InactiveUserServiceInterface {

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
   * 
   * @param type $key
   */
  public function getMailText($key);

  /**
   * Some default e-mail notification strings.
   *
   * @param string $message
   */
  public function mailText($message);

  /**
   * Wrapper for user_mail.
   *
   * @param type $subject
   * @param type $message
   * @param type $period
   * @param type $user
   * @param type $user_list
   */
  public function mail($subject, $message, $period, $user, $user_list);
  
  /**
   * Returns TRUE if the user has ever created a node or a comment.
   *
   * The settings of inactive_user.module allow to protect such
   * users from deletion.
   */
  public function inactiveUserWithContent($uid);

}
