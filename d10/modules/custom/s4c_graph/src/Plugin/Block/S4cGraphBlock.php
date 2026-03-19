<?php

namespace Drupal\s4c_graph\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a block for S4C and ROTI Graph.
 *
 * @Block(
 * id = "s4c_graph_block",
 * admin_label = @Translation("S4C and ROTI Graph Block"),
 * category = @Translation("Custom")
 * )
 */
class S4cGraphBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $output = '
      <div class="gnss-dashboard-container" style="padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
         <h2 style="text-align:center; margin-bottom: 20px; color: #333;">GNSS Real-time Monitoring</h2>
         
         <div class="chart-wrapper" style="position: relative; height: 350px; width: 100%; margin-bottom: 40px;">
           <canvas id="s4Chart"></canvas>
         </div>
         <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">
         <div class="chart-wrapper" style="position: relative; height: 350px; width: 100%; margin-bottom: 20px;">
           <canvas id="rotiChart"></canvas>
         </div>
         <div style="text-align: center; margin-top: 10px;">
            <button id="btn-reset-zoom" style="padding: 10px 20px; cursor: pointer; background-color: #007bff; color: white; border: none; border-radius: 4px;">
               Back to Live (Reset Zoom)
            </button>
         </div>
      </div>
    ';

    return [
      '#type' => 'markup',
      '#markup' => $output,
      '#attached' => [
        'library' => [
           // *** ต้องเป็น format: ชื่อโมดูล/ชื่อไลบรารี ***
           's4c_graph/s4c_bundle', 
        ],
      ],
      '#cache' => [
          'max-age' => 0,
      ],
    ];
  }
}