<?php

namespace Drupal\ntrip_settings\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\user\Entity\User;

/**
 * @Block(
 *   id = "ntrip_settings_block",
 *   admin_label = @Translation("NTRIP Settings Block")
 * )
 */
class NtripSettingsBlock extends BlockBase {

  public function build() {
    $account = \Drupal::currentUser();

    if ($account->isAnonymous()) {
      return [
        '#markup' => '<p>Please login to view NTRIP settings.</p>',
        '#cache' => ['max-age' => 0],
      ];
    }

    $user = User::load($account->id());

    return [
      '#theme' => 'ntrip_settings_page',
      '#mountpoint' => 'BASET113',
      '#ip_address' => '192.168.1.21',
      '#port' => '2101',
      '#username' => $user->get('field_ntrip_username')->value ?? 'N/A',
      '#password' => $user->get('field_ntrip_password')->value ?? 'N/A',
      '#cache' => [
        'contexts' => ['user'],
        'max-age' => 0,
      ],
    ];
  }
}
