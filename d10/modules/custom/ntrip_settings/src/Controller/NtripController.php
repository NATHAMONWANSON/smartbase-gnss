<?php

namespace Drupal\ntrip_settings\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NtripController extends ControllerBase {

  protected $currentUser;

  public function __construct(AccountInterface $current_user) {
    $this->currentUser = $current_user;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user')
    );
  }

  public function showSettings() {
    // Get current logged-in user
    $user = \Drupal\user\Entity\User::load($this->currentUser->id());
    
    // Get username and display name from user entity
    $username = $user ? $user->getAccountName() : 'ntrip_user';
    $display_name = $user ? $user->getDisplayName() : 'User';

    // Return the same NTRIP settings for all users
    return [
      '#theme' => 'ntrip_settings_page',
      '#attached' => [
        'library' => [
          'ntrip_settings/ntrip_styles',
        ],
      ],
      'mountpoint' => 'BASET113',
      'ip_address' => '192.168.1.21',
      'port' => '2101',
      'username' => $username,
      'password' => 'ntrip_pass_default',
      'display_name' => $display_name,
    ];
  }
}