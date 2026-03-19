<?php

namespace Drupal\gnss_monitor\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a 'GNSS Dashboard' Block.
 *
 * @Block(
 * id = "gnss_dashboard_block",
 * admin_label = @Translation("GNSS Professional Dashboard"),
 * category = @Translation("GNSS Monitor"),
 * )
 */
class GnssDashboardBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return [
      // เรียกใช้ Template 'gnss_dashboard' ที่เรากำหนดใน .module
      '#theme' => 'gnss_dashboard',
      
      // ส่งค่าตัวแปรเบื้องต้นไปให้ Template (ถ้ามี)
      '#title' => 'GNSS Real-time Monitor',
      
      // บังคับโหลด Library (CSS/JS) ที่เราตั้งไว้ใน libraries.yml
      '#attached' => [
        'library' => [
          'gnss_monitor/dashboard',
        ],
      ],
    ];
  }

}