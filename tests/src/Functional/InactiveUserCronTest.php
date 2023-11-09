<?php

namespace Drupal\Tests\inactive_user\Functional;

use Drupal\Core\Test\AssertMailTrait;
use Drupal\Tests\BrowserTestBase;

/**
 * Simple test to ensure that main page loads with module enabled.
 *
 * @group inactive_user
 */
class InactiveUserCronTest extends BrowserTestBase {

  use AssertMailTrait;

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['inactive_user', 'user'];

  /**
   * A user with permission to administer site configuration.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->user = $this->drupalCreateUser();

    $config = $this->config('inactive_user.inactiveuseradmin');
    $config->set('inactive_user_admin_email', 'admin@example.com');
    $config->set('inactive_user_notify_admin', INACTIVE_USER_SIX_MONTHS);
    $config->set('inactive_user_notify', INACTIVE_USER_SIX_MONTHS);
    $config->set('inactive_user_notify_text', "Hello %username,\n\n  We haven't seen you at %sitename since %lastaccess, and we miss you!  Please come back and visit us soon at %siteurl.\n\nSincerely,\n  %sitename team");
    $config->set('inactive_user_auto_block_warn', 0);
    $config->set('inactive_user_block_warn_text', "Hello %username,\n\n  We haven't seen you at %sitename since %lastaccess, and we miss you!  This automatic message is to warn you that your account will be disabled in %period unless you come back and visit us before that time.\n\n  Please visit us at %siteurl.\n\nSincerely,\n  %sitename team");
    $config->set('inactive_user_auto_block', INACTIVE_USER_ONE_YEAR);
    $config->set('inactive_user_notify_block', 0);
    $config->set('inactive_user_block_notify_text', "Hello %username,\n\n  This automatic message is to notify you that your account on %sitename has been automatically disabled due to no activity for more than %period.\n\n  Please visit us at %siteurl to have your account re-enabled.\n\nSincerely,\n  %sitename team");
    $config->set('inactive_user_notify_block_admin', 0);
    $config->set('inactive_user_auto_delete_warn', '0');
    $config->set('inactive_user_delete_warn_text', "Hello %username,\n\n  We haven't seen you at %sitename since %lastaccess, and we miss you!  This automatic message is to warn you that your account will be completely removed in %period unless you come back and visit us before that time.\n\n  Please visit us at %siteurl.\n\nSincerely,\n  %sitename team");
    $config->set('inactive_user_auto_delete', '0');
    $config->set('inactive_user_preserve_content', 1);
    $config->set('inactive_user_notify_delete', 0);
    $config->set('inactive_user_delete_notify_text', "Hello %username,\n\n  This automatic message is to notify you that your account on %sitename has been automatically removed due to no activity for more than %period.\n\n  Please visit us at %siteurl if you would like to create a new account.\n\nSincerely,\n  %sitename team");
    $config->set('inactive_user_notify_delete_admin', 0);
    $config->save();
  }

  /**
   * Tests that the user was active a mont ago.
   */
  public function testRunCronActiveMonthAgo() {
    $config = $this->config('inactive_user.inactiveuseradmin');

    /** @var \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager */
    $entity_type_manager = $this->container->get('entity_type.manager');
    $user_storage = $entity_type_manager->getStorage('user');
    $request_time = \Drupal::time()->getRequestTime();

    // Test notifyAdmin().
    $this->user->set('created', $request_time - $config->get('inactive_user_notify_admin') - 1);
    $this->user->save();

    $this->clearMailSystem();
    $this->container->get('inactive_user.notify')->runCron(TRUE);
    $user_storage->resetCache([$this->user->id()]);
    $this->user = $user_storage->load($this->user->id());
    $this->assertEquals([['value' => 1]], $this->user->get('notified_admin')->getValue(), "NotifyAdmin: never signed in.");
    $mails = $this->getMails(['key' => 'inactive_user_notice']);
    // One to the admin, the other to the user.
    $this->assertCount(2, $mails);

    $this->user->set('created', $request_time - $config->get('inactive_user_notify_admin') + 1);
    $this->user->set('login', $request_time - $config->get('inactive_user_notify_admin') + 1);
    $this->user->set('access', $request_time - $config->get('inactive_user_notify_admin') - 1);
    $this->user->set('notified_admin', 0);

    $this->user->save();
    $this->clearMailSystem();
    $this->container->get('inactive_user.notify')->runCron(TRUE);
    $user_storage->resetCache([$this->user->id()]);
    $this->user = $user_storage->load($this->user->id());
    $this->assertEquals([['value' => 1]], $this->user->get('notified_admin')->getValue(), "NotifyAdmin: never signed in.");
    $mails = $this->getMails(['key' => 'inactive_user_notice']);
    $this->assertCount(1, $mails);

    $this->clearMailSystem();
    $this->container->get('inactive_user.notify')->runCron(TRUE);
    $user_storage->resetCache([$this->user->id()]);
    $this->user = $user_storage->load($this->user->id());
    $this->assertEquals([['value' => 1]], $this->user->get('notified_admin')->getValue(), "NotifyAdmin: never signed in.");
    $mails = $this->getMails(['key' => 'inactive_user_notice']);
    $this->assertCount(0, $mails);

    // Test resetAdminNotifications().
    $this->user->set('access', $request_time - INACTIVE_USER_ONE_WEEK + 1);
    $this->user->save();

    $this->clearMailSystem();
    $this->container->get('inactive_user.notify')->runCron(TRUE);
    $user_storage->resetCache([$this->user->id()]);
    $this->user = $user_storage->load($this->user->id());
    $this->assertEquals([['value' => 0]], $this->user->get('notified_admin')->getValue(), "Check warning timestamp set correctly.");

    // Test notifyUser().
    $this->user->set('created', $request_time - $config->get('inactive_user_notify'));
    $this->user->save();

    $this->clearMailSystem();
    $this->container->get('inactive_user.notify')->runCron(TRUE);
    $this->assertEquals([['value' => 1]], $this->user->get('notified_user')->getValue(), "Check warning timestamp set correctly.");


    $user_storage->resetCache([$this->user->id()]);
    $this->user = $user_storage->load($this->user->id());
    $this->assertEquals([], $this->user->get('warned_user_block_timestamp')->getValue(), "Check warning timestamp not set.");
  }

  /**
   * Delete collected emails.
   */
  public function clearMailSystem() {
    $this->container->get('state')->set('system.test_mail_collector', []);
  }

}
