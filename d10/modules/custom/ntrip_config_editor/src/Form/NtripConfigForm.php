<?php

namespace Drupal\ntrip_config_editor\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ntrip_config_editor\NtripLogParser; // <-- [เพิ่ม]
use Symfony\Component\DependencyInjection\ContainerInterface; // <-- [เพิ่ม]

/**
 * Provides a form to edit the NTRIP Caster Access Report file.
 */
class NtripConfigForm extends FormBase {

  /**
   * The log parser service.
   *
   * @var \Drupal\ntrip_config_editor\NtripLogParser
   */
  protected $logParser; // <-- [เพิ่ม]

  /**
   * Constructs a new NtripConfigForm object.
   */
  public function __construct(NtripLogParser $log_parser) { // <-- [เพิ่ม]
    $this->logParser = $log_parser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) { // <-- [เพิ่ม]
    return new static(
      $container->get('ntrip_config_editor.log_parser')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ntrip_config_editor_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // เพิ่มคลาส inline-form เพื่อใช้สไตล์ CSS
    $form['#attributes']['class'][] = 'inline-form';

    // ฟอร์มเลือกช่วงเวลาเป็น Date Picker
    $form['start_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Start Date'),
      '#default_value' => date('Y-m-d'),
    ];
    $form['end_date'] = [
      '#type' => 'date',
      '#title' => $this->t('End Date'),
      '#default_value' => date('Y-m-d'),
    ];

    // ดึง User จาก log ที่มีอยู่
    $user_options = $this->logParser->getUserOptionsFromLogs($form_state); // <-- [แก้ไข]

    // ฟิลเตอร์ผู้ใช้
    $form['filter_user'] = [
      '#type' => 'select',
      '#title' => $this->t('Filter by User'),
      '#options' => $user_options,
      '#default_value' => 'all',
    ];

    // โหลด config ที่บันทึกไว้
    $config = \Drupal::config('ntrip_config_editor.settings');
    $default_time_rate = $config->get('time_rate') ?? 0.2;
    $default_data_rate = $config->get('data_rate') ?? 1.0;

    // เพิ่มตัวเลือกประเภทการคำนวณ
    $form['cost_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Cost Calculation Method'),
      '#options' => [
        'time' => $this->t('Calculate by Time'),
        'data' => $this->t('Calculate by Data'),
      ],
      '#default_value' => $form_state->getValue('cost_type') ?? 'time',
      '#required' => TRUE,
    ];

    // ช่องกรอกอัตราค่าบริการตามเวลา
    $form['time_rate'] = [
      '#type' => 'number',
      '#title' => $this->t('Time Rate (Baht/minute)'),
      '#default_value' => $form_state->getValue('time_rate') ?? $default_time_rate,
      '#step' => 0.01,
      '#min' => 0,
      '#required' => TRUE,
      '#size' => 10,
    ];

    // ช่องกรอกอัตราค่าบริการตามข้อมูล
    $form['data_rate'] = [
      '#type' => 'number',
      '#title' => $this->t('Data Rate (Baht/MB)'),
      '#default_value' => $form_state->getValue('data_rate') ?? $default_data_rate,
      '#step' => 0.01,
      '#min' => 0,
      '#required' => TRUE,
      '#size' => 10,
    ];

    // ปุ่ม Apply
    $form['apply_filters'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply filters'),
      '#attributes' => ['class' => ['form-item-submit']],
    ];

    // โหลด log ที่ parse แล้ว
    $formatted_log = $form_state->get('formatted_log');
    
    // เราควรใช้ค่าจาก form_state ที่ submit มา หรือค่า default ที่โหลดมา
    $cost_type = $form_state->getValue('cost_type') ?? 'time';
    $time_rate = $form_state->getValue('time_rate') ?? $default_time_rate; // <-- [แก้ไข]
    $data_rate = $form_state->getValue('data_rate') ?? $default_data_rate; // <-- [แก้ไข]

    // ตาราง Summary by User
    if ($formatted_log && isset($formatted_log['user_summary'])) {
      $rows = [];
      foreach ($formatted_log['user_summary'] as $user => $stations) {
        foreach ($stations as $station => $data) {
          
          // คำนวณค่าบริการตามข้อมูล
          $data_in_mb = $data['bytes'] / 1048576; // (1024 * 1024)
          $custom_value_data = $data_in_mb * $data_rate;  // ค่าบริการตามข้อมูล
          
          // คำนวณค่าบริการตามเวลา
          $minutes = $data['duration'] / 60; // แปลงวินาทีเป็นนาที
          $custom_value_time = $minutes * $time_rate;  // ค่าบริการตามเวลา

          // เลือกค่าที่จะแสดงตาม cost_type
          $cost_value = ($cost_type === 'time') ? $custom_value_time : $custom_value_data;
          $rate_display = ($cost_type === 'time') ? $time_rate : $data_rate;
          $unit = ($cost_type === 'time') ? 'min' : 'MB';

          // เพิ่มแถวข้อมูลลงในผลลัพธ์
          $rows[] = [
            $user,
            $station,
            $data['duration'] . ' sec',
            $this->logParser->formatBytes($data['bytes']), // <-- [แก้ไข]
            number_format($cost_value, 2) . ' Baht',
          ];
        }
      }
      
      $form['user_summary_table'] = [
        '#type' => 'table',
        '#title' => $this->t('Summary by User & Station'),
        '#header' => [
          $this->t('User'),
          $this->t('Station'),
          $this->t('Total Duration'),
          $this->t('Total Bytes'),
          $this->t('Total Cost (by @type @ @rate Baht/@unit)', [
            '@type' => ucfirst($cost_type),
            '@rate' => number_format($rate_display, 2),
            '@unit' => $unit
          ]),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No user summary data.'),
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $start_date = $form_state->getValue('start_date');
    $end_date = $form_state->getValue('end_date');
    $filter_user = $form_state->getValue('filter_user');

    $start_timestamp = strtotime($start_date);
    $end_timestamp = strtotime($end_date . ' 23:59:59'); // <-- [ปรับปรุง] ให้รวมวันสุดท้ายทั้งวัน

    $log_files = $this->logParser->getLogFilesByDate($start_timestamp, $end_timestamp); // <-- [แก้ไข]

    $log_content = '';
    foreach ($log_files as $log_file) {
      if (file_exists($log_file) && is_readable($log_file)) {
        $log_content .= file_get_contents($log_file);
      }
      else {
        $this->messenger()->addWarning($this->t('Cannot read the log file: @file', ['@file' => $log_file]));
      }
    }

    if ($filter_user !== 'all') {
      $log_content = $this->logParser->filterLogByUser($log_content, $filter_user); // <-- [แก้ไข]
    }

    $formatted_log = $this->logParser->formatLogContent($log_content); // <-- [แก้ไข]

    $form_state->set('formatted_log', $formatted_log);
    $this->messenger()->addStatus($this->t('Log data loaded successfully for period: %start to %end', [
      '%start' => $start_date,
      '%end' => $end_date
    ]));
    $form_state->setRebuild();
  }
}