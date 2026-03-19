<?php

namespace Drupal\rtk_display\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * @Block(
 * id = "rtk_display_block",
 * admin_label = @Translation("RTK Real-time Display Block")
 * )
 */
class RtkBlock extends BlockBase {
  public function build() {
    // สร้างโครง HTML และปรับ Style ให้เหมือนกับ .ntrip-container ทุกประการ
    $markup = '
      <div id="rtk-data-wrapper">
        <h2>RTK Real-time Status</h2>
        <p><strong>Status:</strong> <span id="rtk-status" style="font-weight:bold; color: #f0ad4e;">Waiting for data...</span></p>
        <p><strong>Latitude:</strong> <span id="rtk-lat">N/A</span></p>
        <p><strong>Longitude:</strong> <span id="rtk-lon">N/A</span></p>
        <p><strong>Height:</strong> <span id="rtk-h">N/A</span></p>
      </div>';
    
    $build = [];
    $build['#markup'] = $markup;

    // สั่งให้ Drupal แนบ JavaScript ที่เรากำหนดไว้ใน libraries.yml เข้ามาในหน้านี้
    $build['#attached']['library'][] = 'rtk_display/rtk-subscriber';

    return $build;
  }
}