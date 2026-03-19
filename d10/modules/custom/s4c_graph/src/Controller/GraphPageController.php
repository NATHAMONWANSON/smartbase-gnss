<?php

namespace Drupal\s4c_graph\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Controller for GNSS Graph Page.
 */
class GraphPageController extends ControllerBase {

  /**
   * Returns the GNSS monitoring page with charts.
   *
   * @return array
   *   A render array for the page.
   */
  public function page() {
    return [
      '#type' => 'inline_template',
      '#template' => '
        <div class="gnss-dashboard-container">
          <h2 style="text-align:center;">GNSS Real-time Monitoring</h2>
          
          <div class="chart-wrapper" style="position: relative; height: 400px; margin-bottom: 40px;">
            <canvas id="s4Chart"></canvas>
          </div>

          <hr>

          <div class="chart-wrapper" style="position: relative; height: 400px;">
            <canvas id="rotiChart"></canvas>
          </div>
          
          <div style="text-align: center; margin-top: 20px;">
            <button id="btn-reset-zoom" class="button button--primary">Reset Zoom</button>
          </div>
        </div>
      ',
      '#attached' => [
        'library' => [
          's4c_graph/gnss_chart_lib',
        ],
      ],
    ];
  }

}
