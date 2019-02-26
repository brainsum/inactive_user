<?php

namespace Drupal\Tests\inactive_user\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Simple test to ensure that main page loads with module enabled.
 *
 * @group inactive_user
 */
class InactiveUserCronTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['inactive_user', 'user'];

  /**
   * A user with permission to administer site configuration.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->user = $this->drupalCreateUser();
  }

  /**
   * Tests that the user was active a mont ago.
   */
  public function testRunCronActiveMonthAgo() {
    $request_time = \Drupal::time()->getRequestTime();
    $this->user->set('created', $request_time - ONE_YEAR);
    $this->user->set('access', $request_time - ONE_MONTH);
    $this->user->save();
    $this->container->get('inactive_user.notify')->runCron(TRUE);
  }

}
