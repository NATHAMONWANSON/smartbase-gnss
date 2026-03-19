<?php

namespace Drupal\ntrip_manager\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;

class NtripManagerForm extends FormBase {

  // กำหนด Path ของไฟล์คอนฟิก
  const CONFIG_PATH = '/etc/ntripcaster/';
  const CLIENTMOUNTS_FILE = self::CONFIG_PATH . 'clientmounts.aut';
  const GROUPS_FILE = self::CONFIG_PATH . 'groups.aut';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ntrip_manager_config_form';
  }

  // ============== HELPER FUNCTIONS (ฟังก์ชันช่วย) ==============

  /**
   * อ่านข้อมูลจากไฟล์ clientmounts.aut
   * @return array ['/MOUNT1' => ['group1', 'group2']]
   */
  private function _readClientMounts() {
    $mounts = [];
    if (is_readable(self::CLIENTMOUNTS_FILE)) {
      $lines = file(self::CLIENTMOUNTS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      foreach ($lines as $line) {
        if (strpos($line, ';') !== false) {
          $parts = explode(';', $line);
          $mountName = array_shift($parts);
          $mounts[$mountName] = $parts;
        }
      }
    }
    return $mounts;
  }

private function readClientmounts() {
    $mounts = [];
    $file = "/etc/ntripcaster/clientmounts.aut";

    if (is_readable($file)) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            
            // ข้าม comment (#...) และบรรทัดว่าง
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            // รูปแบบ: /MOUNTNAME:users,...
            if (strpos($line, '/') === 0 && strpos($line, ':') !== false) {
                list($mountName, ) = explode(':', $line, 2);
                
                // เก็บ mountName พร้อม / (ไม่ตัดออก)
                $mounts[$mountName] = $mountName;
            }
        }
    }

    return $mounts;
}

public function renameCommentSubmit(array &$form, FormStateInterface $form_state) {
    // รับค่าจาก form และ trim whitespace
    $selected = trim($form_state->getValue('select_mountpoint_group_to_rename'));
    $new = trim($form_state->getValue('new_mountpoint_group_name'));
    $file = "/etc/ntripcaster/clientmounts.aut";

    // Validation
    if (empty($selected) || empty($new)) {
        $this->messenger()->addError($this->t('Both old and new group names are required.'));
        return;
    }

    // ตรวจสอบว่าไฟล์สามารถอ่านได้
    if (!is_readable($file)) {
        $this->messenger()->addError($this->t('File not readable: @file', ['@file' => $file]));
        return;
    }

    // อ่านไฟล์เป็น array ทีละบรรทัด
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        $this->messenger()->addError($this->t('Failed to read file: @file', ['@file' => $file]));
        return;
    }

    $changed = false;
    
    // Log สำหรับ debug
    \Drupal::logger('ntrip')->notice('Attempting to rename comment: [@old] => [@new]', [
        '@old' => $selected, 
        '@new' => $new
    ]);

    // Loop ผ่านทุกบรรทัด
    foreach ($lines as $i => $line) {
        $trimmedLine = trim($line);
        
        // ตรวจสอบว่าเป็น comment line
        if (strpos($trimmedLine, '#') === 0) {
            // ดึง comment text ออกมา (ตัด # และ whitespace)
            $commentText = trim(substr($trimmedLine, 1));
            
            // เปรียบเทียบกับค่าที่เลือก (case-sensitive)
            if ($commentText === $selected) {
                // แทนที่ด้วยค่าใหม่ (รักษารูปแบบ # ไว้)
                $lines[$i] = '# ' . $new;
                $changed = true;
                
                \Drupal::logger('ntrip')->notice('Successfully renamed comment on line @line: [@old] => [@new]', [
                    '@line' => $i + 1,
                    '@old' => $selected,
                    '@new' => $new
                ]);
                
                // หยุด loop เมื่อเจอและแก้ไขแล้ว (ป้องกันการแก้หลายบรรทัด)
                break;
            }
        }
    }

    // บันทึกกลับเข้าไฟล์ถ้ามีการเปลี่ยนแปลง
    if ($changed) {
        // ใช้ _writeFileSecurely เพื่อเขียนผ่าน sudo
        $content = implode("\n", $lines) . "\n";
        $this->_writeFileSecurely($file, $content);
        
        $this->messenger()->addStatus($this->t('Comment renamed successfully from "@old" to "@new"', [
            '@old' => $selected,
            '@new' => $new
        ]));
    } else {
        \Drupal::logger('ntrip')->warning('No matching comment found: [@selected]', [
            '@selected' => $selected
        ]);
        
        $this->messenger()->addWarning($this->t('No matching comment found: "@selected". Please ensure the comment exists exactly as shown.', [
            '@selected' => $selected
        ]));
    }
}

public function submitRenameMountpoint(array &$form, FormStateInterface $form_state) {
    // 1. รับค่าจากฟอร์ม
    $oldMountpoint = $form_state->getValue('select_mountpoint_to_rename');
    $newMountpoint = trim($form_state->getValue('new_mountpoint_name_input'));

    // 2. ตรวจสอบข้อมูลเบื้องต้น (Validation)
    if (empty($oldMountpoint)) {
        $this->messenger()->addError($this->t('Please select a mountpoint to rename.'));
        return;
    }
    if (empty($newMountpoint)) {
        $this->messenger()->addError($this->t('Please enter the new mountpoint name.'));
        return;
    }
    if (strpos($newMountpoint, '/') !== 0) {
        $this->messenger()->addError($this->t('New mountpoint name must begin with a forward slash (/).'));
        return;
    }
    if ($oldMountpoint === $newMountpoint) {
        $this->messenger()->addWarning($this->t('The new mountpoint name is the same as the old one. No changes were made.'));
        return;
    }

    $file = self::CLIENTMOUNTS_FILE;

    // 3. ตรวจสอบว่าไฟล์สามารถอ่านได้
    if (!is_readable($file)) {
        $this->messenger()->addError($this->t('File not readable: @file', ['@file' => $file]));
        return;
    }

    // ตรวจสอบว่าชื่อใหม่ซ้ำกับ Mountpoint ที่มีอยู่หรือไม่
    $existingMounts = $this->readClientmounts();
    if (isset($existingMounts[$newMountpoint])) {
        $this->messenger()->addError($this->t('The new mountpoint name "@mount" already exists. Please choose a different name.', ['@mount' => $newMountpoint]));
        return;
    }

    // 4. อ่านไฟล์ทั้งหมด
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        $this->messenger()->addError($this->t('Failed to read file: @file', ['@file' => $file]));
        return;
    }

    $mountFound = false;

    // 5. วน Loop เพื่อค้นหาและแก้ไขบรรทัดที่ต้องการ
    foreach ($lines as $i => &$line) { // ใช้ & เพื่อให้แก้ไขค่าใน array ได้โดยตรง
        $trimmedLine = trim($line);
        if (strpos($trimmedLine, $oldMountpoint . ':') === 0) {
            // แยกส่วนชื่อ mountpoint และรายชื่อ groups
            list(, $groupsStr) = explode(':', $trimmedLine, 2);
            
            // สร้างบรรทัดใหม่ด้วยชื่อ mountpoint ใหม่ แต่ยังคง groups เดิมไว้
            $line = $newMountpoint . ':' . $groupsStr;
            
            $mountFound = true;
            break; // หยุด loop เมื่อเจอและแก้ไขแล้ว
        }
    }

    // 6. บันทึกการเปลี่ยนแปลงลงไฟล์
    if ($mountFound) {
        $content = implode("\n", $lines) . "\n";
        $this->_writeFileSecurely($file, $content);
        
        \Drupal::logger('ntrip')->notice('Renamed mountpoint from "@old" to "@new"', [
            '@old' => $oldMountpoint,
            '@new' => $newMountpoint,
        ]);

        $this->messenger()->addStatus($this->t('Successfully renamed mountpoint from "@old" to "@new".', [
            '@old' => $oldMountpoint,
            '@new' => $newMountpoint,
        ]));
    } else {
        $this->messenger()->addError($this->t('Mountpoint "@mount" not found in the file. No changes were made.', ['@mount' => $oldMountpoint]));
    }
}

public function submitAddMountpoint(array &$form, FormStateInterface $form_state) {
  $selectedGroup = trim($form_state->getValue('select_mountpoint_group'));
  $newMountName = trim($form_state->getValue('new_mountpoint_name'));
  $allowedGroups = trim($form_state->getValue('allowed_user_groups_new'));
  
  // Validation
  if (empty($selectedGroup)) {
    $this->messenger()->addError($this->t('Please select a mountpoint group.'));
    return;
  }
  
  if (empty($newMountName)) {
    $this->messenger()->addError($this->t('Please enter a mountpoint name.'));
    return;
  }
  
  if (empty($allowedGroups)) {
    $this->messenger()->addError($this->t('Please enter allowed user groups.'));
    return;
  }
  
  $file = "/etc/ntripcaster/clientmounts.aut";
  
  // ตรวจสอบว่าไฟล์อ่านได้
  if (!is_readable($file)) {
    $this->messenger()->addError($this->t('File not readable: @file', ['@file' => $file]));
    return;
  }
  
  // อ่านไฟล์
  $lines = file($file, FILE_IGNORE_NEW_LINES);
  if ($lines === false) {
    $this->messenger()->addError($this->t('Failed to read file: @file', ['@file' => $file]));
    return;
  }
  
  // เตรียม mountpoint ใหม่
  $mountPrefix = (strpos($newMountName, '/') === 0) ? '' : '/';
  $newMountLine = $mountPrefix . $newMountName . ':' . $allowedGroups;
  
  // ตรวจสอบว่า mountpoint ซ้ำหรือไม่
  foreach ($lines as $line) {
    $trimmedLine = trim($line);
    if (strpos($trimmedLine, '/') === 0 && strpos($trimmedLine, ':') !== false) {
      list($existingMount, ) = explode(':', $trimmedLine, 2);
      if ($existingMount === $mountPrefix . $newMountName) {
        $this->messenger()->addError($this->t('Mountpoint @mount already exists.', ['@mount' => $mountPrefix . $newMountName]));
        return;
      }
    }
  }
  
  // หา comment line ที่ตรงกับ group ที่เลือก
  $insertPosition = -1;
  $foundGroup = false;
  
  for ($i = 0; $i < count($lines); $i++) {
    $trimmedLine = trim($lines[$i]);
    
    // เจอ comment ที่ตรงกับ group ที่เลือก
    if (strpos($trimmedLine, '#') === 0) {
      $commentText = trim(substr($trimmedLine, 1));
      
      if ($commentText === $selectedGroup) {
        $foundGroup = true;
        $insertPosition = $i + 1;
        
        // หาตำแหน่งสุดท้ายของ mountpoint ในกลุ่มนี้
        for ($j = $i + 1; $j < count($lines); $j++) {
          $nextLine = trim($lines[$j]);
          
          // ถ้าเจอ comment ใหม่ หรือ บรรทัดว่าง แสดงว่าจบกลุ่ม
          if (empty($nextLine) || strpos($nextLine, '#') === 0) {
            break;
          }
          
          // ถ้ายังเป็น mountpoint อยู่ให้เลื่อน position ต่อ
          if (strpos($nextLine, '/') === 0) {
            $insertPosition = $j + 1;
          }
        }
        break;
      }
    }
  }
  
  if (!$foundGroup) {
    $this->messenger()->addError($this->t('Group "@group" not found in file.', ['@group' => $selectedGroup]));
    return;
  }
  
  // แทรก mountpoint ใหม่
  array_splice($lines, $insertPosition, 0, $newMountLine);
  
  // เขียนกลับเข้าไฟล์
  $content = implode("\n", $lines) . "\n";
  $this->_writeFileSecurely($file, $content);
  
  \Drupal::logger('ntrip')->notice('Added mountpoint @mount under group @group', [
    '@mount' => $newMountLine,
    '@group' => $selectedGroup
  ]);
  
  $this->messenger()->addStatus($this->t('Successfully added mountpoint "@mount" under group "@group"', [
    '@mount' => $mountPrefix . $newMountName,
    '@group' => $selectedGroup
  ]));
}

public function submitDeleteMountpoint(array &$form, FormStateInterface $form_state) {
  $mountToDelete = trim($form_state->getValue('mountpoint_to_delete'));
  $confirmText = trim($form_state->getValue('confirm_delete_text'));
  
  // Validation
  if (empty($mountToDelete)) {
    $this->messenger()->addError($this->t('Please select a mountpoint to delete.'));
    return;
  }
  
  // ตรวจสอบว่าพิมพ์ "Confirm" ถูกต้อง (case-sensitive)
  if ($confirmText !== 'Confirm') {
    $this->messenger()->addError($this->t('You must type "Confirm" exactly to delete the mountpoint.'));
    return;
  }
  
  $file = "/etc/ntripcaster/clientmounts.aut";
  
  // ตรวจสอบว่าไฟล์อ่านได้
  if (!is_readable($file)) {
    $this->messenger()->addError($this->t('File not readable: @file', ['@file' => $file]));
    return;
  }
  
  // อ่านไฟล์
  $lines = file($file, FILE_IGNORE_NEW_LINES);
  if ($lines === false) {
    $this->messenger()->addError($this->t('Failed to read file: @file', ['@file' => $file]));
    return;
  }
  
  $deleted = false;
  $deletedLine = '';
  
  // เตรียมชื่อ mountpoint ให้มี / ข้างหน้า (ถ้ายังไม่มี)
  $mountToDeleteWithSlash = (strpos($mountToDelete, '/') === 0) ? $mountToDelete : '/' . $mountToDelete;
  
  // หาและลบ mountpoint
  foreach ($lines as $i => $line) {
    $trimmedLine = trim($line);
    
    // ตรวจสอบว่าเป็นบรรทัด mountpoint
    if (strpos($trimmedLine, '/') === 0 && strpos($trimmedLine, ':') !== false) {
      list($mountName, ) = explode(':', $trimmedLine, 2);
      
      // ถ้าตรงกับ mountpoint ที่เลือก
      if ($mountName === $mountToDeleteWithSlash) {
        $deletedLine = $line;
        unset($lines[$i]);
        $deleted = true;
        break;
      }
    }
  }
  
  if (!$deleted) {
    $this->messenger()->addError($this->t('Mountpoint "@mount" not found in file.', ['@mount' => $mountToDelete]));
    return;
  }
  
  // Re-index array หลังจาก unset
  $lines = array_values($lines);
  
  // เขียนกลับเข้าไฟล์
  $content = implode("\n", $lines) . "\n";
  $this->_writeFileSecurely($file, $content);
  
  \Drupal::logger('ntrip')->notice('Deleted mountpoint: @mount', [
    '@mount' => $deletedLine
  ]);
  
  $this->messenger()->addStatus($this->t('Successfully deleted mountpoint "@mount"', [
    '@mount' => $mountToDelete
  ]));
}

  /**
   * อ่านข้อมูลกลุ่มจากไฟล์ groups.aut
   * @return array ['group1' => ['user1', 'user2']]
   */
private function _readGroups() {
  $groups = [];
  $file = "/etc/ntripcaster/clientmounts.aut";
  if (is_readable($file)) {
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
      $line = trim($line);
      // ตรวจสอบว่าเป็น comment line (ขึ้นต้นด้วย #)
      if (strpos($line, '#') === 0) {
        // ตัด # และ whitespace ออก แล้วเก็บค่า
        $comment = trim(substr($line, 1));
        // เก็บเฉพาะที่ไม่ใช่ค่าว่าง
        if (!empty($comment)) {
          $groups[] = $comment;
        }
      }
    }
  }
  return $groups;
}
/**
 * อ่านและจัดโครงสร้างข้อมูลจาก clientmounts.aut สำหรับการแสดงผลเป็นตาราง
 * โดยจะจัดกลุ่มตาม Comment (#) ที่อยู่ก่อนหน้า
 *
 * @return array
 * ผลลัพธ์จะมีโครงสร้างแบบ:
 * [
 * 'GroupName1' => [
 * ['mount' => '/MOUNT1', 'groups' => 'groupA,groupB'],
 * ['mount' => '/MOUNT2', 'groups' => 'groupC'],
 * ],
 * 'GroupName2' => [
 * ['mount' => '/MOUNT3', 'groups' => 'groupD'],
 * ]
 * ]
 */
private function _parseClientMountsForDisplay() {
    $structuredMounts = [];
    // กำหนดกลุ่มเริ่มต้นสำหรับ Mountpoint ที่ไม่มี # นำหน้า
    $currentGroup = 'Ungrouped'; 
    $file = self::CLIENTMOUNTS_FILE;

    if (is_readable($file)) {
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            if (empty($trimmedLine)) {
                continue; // ข้ามบรรทัดว่าง
            }

            // ถ้าขึ้นต้นด้วย # แสดงว่าเป็นชื่อ "Mountpoint Group" ใหม่
            if (strpos($trimmedLine, '#') === 0) {
                $currentGroup = trim(substr($trimmedLine, 1));
            }
            // ถ้าเป็นบรรทัดของ mountpoint (มี : และขึ้นต้นด้วย /)
            elseif (strpos($trimmedLine, '/') === 0 && strpos($trimmedLine, ':') !== false) {
                list($mount, $groups) = explode(':', $trimmedLine, 2);
                
                // สร้าง array สำหรับกลุ่มนี้ ถ้ายังไม่มี
                if (!isset($structuredMounts[$currentGroup])) {
                    $structuredMounts[$currentGroup] = [];
                }
                
                // เพิ่มข้อมูล mountpoint เข้าไปในกลุ่มปัจจุบัน
                $structuredMounts[$currentGroup][] = [
                    'mount' => trim($mount),
                    'groups' => trim($groups),
                ];
            }
        }
    }
    return $structuredMounts;
}  
  /**
   * เขียนข้อมูลลงไฟล์อย่างปลอดภัยด้วย sudo tee
   */
  private function _writeFileSecurely($file_path, $content) {
    $command = "echo " . escapeshellarg($content) . " | sudo /usr/bin/tee " . $file_path;
    shell_exec($command . ' > /dev/null 2>&1');
  }

  // ============== FORM BUILDING (ส่วนสร้างฟอร์ม) ==============

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // 1. เรียกใช้ฟังก์ชันใหม่เพื่อดึงและจัดกลุ่มข้อมูล
    $mountpointData = $this->_parseClientMountsForDisplay();

    // 2. สร้าง Element หลักสำหรับตาราง
    $form['mountpoint_table'] = [
        '#type' => 'details',
        '#title' => $this->t('Current Mountpoints Configuration'),
        '#open' => TRUE,
        '#weight' => -10, // กำหนดค่า weight เป็นลบเพื่อให้แสดงผลเป็นอันดับแรก
    ];

    // 3. กำหนดหัวตาราง
    $header = [
        'mountpoint_group' => $this->t('Mountpoint Group'),
        'mountpoint_name' => $this->t('Mountpoint'),
        'allowed_groups' => $this->t('Allowed User Groups'),
    ];

    $rows = [];
    // 4. วนลูปข้อมูลที่จัดโครงสร้างไว้แล้วเพื่อสร้างแถวของตาราง
    foreach ($mountpointData as $groupName => $mounts) {
        $isFirstRowOfGroup = true;
        foreach ($mounts as $mountInfo) {
            if ($isFirstRowOfGroup) {
                // สำหรับแถวแรกของกลุ่ม: เราจะสร้าง cell ของชื่อกลุ่มพร้อมกำหนด rowspan
                $rows[] = [
                    'mountpoint_group' => [
                        'data' => $groupName,
                        'rowspan' => count($mounts), // ทำให้ cell ของชื่อกลุ่มยาวเท่ากับจำนวน mountpoint ในกลุ่มนั้นๆ
                    ],
                    'mountpoint_name' => $mountInfo['mount'],
                    'allowed_groups' => $mountInfo['groups'],
                ];
                $isFirstRowOfGroup = false; // ตั้งค่าสถานะว่าแถวแรกของกลุ่มได้ถูกสร้างไปแล้ว
            } else {
                // สำหรับแถวถัดๆ ไปในกลุ่มเดียวกัน: จะมีแค่ 2 cell เพราะ cell แรกถูกรวมไปแล้ว
                $rows[] = [
                    'mountpoint_name' => $mountInfo['mount'],
                    'allowed_groups' => $mountInfo['groups'],
                ];
            }
        }
    }

    // 5. สร้าง Render Array ของตาราง
    $form['mountpoint_table']['table'] = [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No mountpoint configuration found in @file.', ['@file' => self::CLIENTMOUNTS_FILE]),
    ];

    $clientMountsData = $this->_readClientMounts();
    $groupsData = $this->_readGroups();
    $clientMounts = $this->readClientmounts();

    // $mountpointOptions = ['' => '- Select mountpoint to edit -'] + array_combine(array_keys($clientMounts), array_keys($clientMounts));
    $mountpointOptions = ['' => '- Select mountpoint to edit -'] + $clientMounts;
    $groupOptions = ['' => '- Select mountpoint group to rename -'] + $groupsData;

    $form['#prefix'] = '<div class="ntrip-manager-container">';
    $form['#suffix'] = '</div>';

    // --- คอลัมน์ซ้าย: Edit Existing Mountpoint & Rename Group ---
    $form['left_column'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ntrip-manager-column']],
    ];
    $form['left_column']['edit_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Edit Existing Mountpoint & Rename Mountpoint Group'),
      '#open' => TRUE,
    ];
    $form['left_column']['edit_section']['select_mountpoint_to_edit'] = [
      '#type' => 'select',
      '#title' => $this->t('Select Mountpoint to Edit'),
      '#options' => $mountpointOptions,
      '#ajax' => [
        'callback' => '::updateAllowedGroupsAjax',
        'event' => 'change',
        'wrapper' => 'allowed-user-groups-wrapper',
      ],
    ];
    $form['left_column']['edit_section']['allowed_user_groups_edit'] = [
      '#markup' => '<h8>Allowed User Groups</h8>',
    ];
   
    //$form['left_column']['edit_section']['hr1'] = ['#markup' => '<hr>'];

    $form['left_column']['edit_section']['action'] = [
      '#type' => 'select',
      '#title' => $this->t('Action'),
      '#options' => [
        '' => '- Select an action -',
        'add' => 'Add Groups',
        'remove' => 'Remove Groups',
      ],
    ];
    $form['left_column']['edit_section']['user_groups_to_modify'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User Groups to Modify'),
      '#description' => $this->t('Comma-separated list of user group names. Example: group1,group2'),
    ];
    $form['left_column']['edit_section']['submit_modify_groups'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply Action to Selected Mountpoint'),
      '#name' => 'op_modify_groups',
    ];

    // ส่วนนี้อยู่ใน buildForm() - แทนที่ส่วน rename group

$form['left_column']['edit_section']['hr2'] = ['#markup' => '<hr>'];

// เปลี่ยนจาก array_combine เป็นการสร้าง options ที่ถูกต้อง
$groupOptions = ['' => '- Select mountpoint group to rename -'];
foreach ($groupsData as $group) {
    $groupOptions[$group] = $group;
}

$form['left_column']['edit_section']['select_mountpoint_group_to_rename'] = [
  '#type' => 'select',
  '#title' => $this->t('Select Mountpoint Group to Rename'),
  '#options' => $groupOptions,
  '#required' => FALSE,
];

$form['left_column']['edit_section']['new_mountpoint_group_name'] = [
  '#type' => 'textfield',
  '#title' => $this->t('New Mountpoint Group Name'),
  '#description' => $this->t('Enter the new name for the selected group.'),
  '#required' => FALSE,
];

$form['left_column']['edit_section']['submit_rename_group'] = [
  '#type' => 'submit',
  '#value' => $this->t('Rename Mountpoint Group'),
  '#name' => 'op_rename_group',
  '#submit' => ['::renameCommentSubmit'],
  // แก้ไข limit_validation_errors ให้ถูกต้อง
  '#limit_validation_errors' => [
    ['select_mountpoint_group_to_rename'],
    ['new_mountpoint_group_name']
  ],
];
// เพิ่มเส้นคั่นเพื่อแยกส่วนฟอร์มให้ชัดเจน
$form['left_column']['edit_section']['hr_rename_mountpoint'] = ['#markup' => '<hr style="margin-top: 2rem; margin-bottom: 2rem;">'];

// Dropdown สำหรับเลือก Mountpoint ที่จะเปลี่ยนชื่อ
$form['left_column']['edit_section']['select_mountpoint_to_rename'] = [
    '#type' => 'select',
    '#title' => $this->t('Select Mountpoint to Rename'),
    '#options' => $mountpointOptions, // ใช้ $mountpointOptions ที่มีอยู่แล้ว
    '#description' => $this->t('Select the mountpoint you wish to rename.'),
];

// Textfield สำหรับกรอกชื่อ Mountpoint ใหม่
$form['left_column']['edit_section']['new_mountpoint_name_input'] = [
    '#type' => 'textfield',
    '#title' => $this->t('New Mountpoint Name'),
    '#description' => $this->t('Enter the new name, including the leading slash (e.g., /NEW_NAME).'),
];

// ปุ่ม Submit สำหรับการเปลี่ยนชื่อ Mountpoint
$form['left_column']['edit_section']['submit_rename_mountpoint'] = [
    '#type' => 'submit',
    '#value' => $this->t('Rename Mountpoint'),
    '#name' => 'op_rename_mountpoint',
    '#submit' => ['::submitRenameMountpoint'], // เรียกใช้ฟังก์ชันที่เราสร้างในขั้นตอนที่ 1
    '#limit_validation_errors' => [ // จำกัดการตรวจสอบข้อมูลเฉพาะฟิลด์ที่เกี่ยวข้อง
        ['select_mountpoint_to_rename'],
        ['new_mountpoint_name_input'],
    ],
];
    // --- คอลัมน์ขวา Add/Delete  ---
    $form['right_column'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ntrip-manager-column']],
    ];
    $form['right_column']['add_delete_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Add/Delete Mountpoint'),
      '#open' => TRUE,
    ];

    $form['right_column']['add_delete_section']['select_mountpoint_group'] = [
  '#type' => 'select',
  '#title' => $this->t('Select Mountpoint Group'),
  '#options' => $groupOptions,
  '#description' => $this->t('Select which group to add the new mountpoint under.'),
];

$form['right_column']['add_delete_section']['new_mountpoint_name'] = [
  '#type' => 'textfield',
  '#title' => $this->t('New Mountpoint Name'),
  '#description' => $this->t('Enter mountpoint name (without /). Example: TEST2'),
  '#required' => FALSE,
];

$form['right_column']['add_delete_section']['allowed_user_groups_new'] = [
  '#type' => 'textfield',
  '#title' => $this->t('Allowed User Groups'),
  '#description' => $this->t('Comma-separated list of user group names. Example: testg,userg1'),
  '#required' => FALSE,
];

$form['right_column']['add_delete_section']['submit_add'] = [
  '#type' => 'submit',
  '#value' => $this->t('Add New Mountpoint'),
  '#name' => 'op_add',
  '#submit' => ['::submitAddMountpoint'],
  '#limit_validation_errors' => [
    ['select_mountpoint_group'],
    ['new_mountpoint_name'],
    ['allowed_user_groups_new']
  ],
];

 $form['right_column']['add_delete_section']['hr3'] = ['#markup' => '<hr>'];

$form['right_column']['add_delete_section']['mountpoint_to_delete'] = [
  '#type' => 'select',
  '#title' => $this->t('Select Mountpoint to Delete'),
  '#description' => $this->t('Select a mountpoint to delete from the file.'), 
  '#options' => $mountpointOptions,
];

$form['right_column']['add_delete_section']['confirm_delete_text'] = [
  '#type' => 'textfield',
  '#title' => $this->t('Type "Confirm" to Delete'),
  '#description' => $this->t('You must type the word <strong>Confirm</strong> (case-sensitive) to proceed with deletion.'),
  '#required' => FALSE,
];

$form['right_column']['add_delete_section']['submit_delete'] = [
  '#type' => 'submit',
  '#value' => $this->t('Delete Selected Mountpoint'),
  '#name' => 'op_delete',
  '#submit' => ['::submitDeleteMountpoint'],
  '#limit_validation_errors' => [
    ['mountpoint_to_delete'],
    ['confirm_delete_text']
  ],
];

    return $form;
  }
  
  // ============== AJAX CALLBACK ==============

  public function updateAllowedGroupsAjax(array &$form, FormStateInterface $form_state) {
    $selectedMount = $form_state->getValue('select_mountpoint_to_edit');
    $clientMountsData = $this->_readClientMounts();
    $groups = (!empty($selectedMount) && isset($clientMountsData[$selectedMount])) ? implode(', ', $clientMountsData[$selectedMount]) : '';
    $response = new AjaxResponse();
    $response->addCommand(new InvokeCommand('#edit-allowed-user-groups-edit', 'val', [$groups]));
    return $response;
  }

  // ============== SUBMIT HANDLERS ==============

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $button_name = $form_state->getTriggeringElement()['#name'];
    switch ($button_name) {
      case 'op_add': $this->submitAdd($form, $form_state); break;
      case 'op_delete': $this->submitDelete($form, $form_state); break;
      case 'op_update_allowed_groups': $this->submitUpdateAllowedGroups($form, $form_state); break;
      case 'op_modify_groups': $this->submitModifyGroups($form, $form_state); break;
      case 'op_rename_group': $this->submitRenameGroup($form, $form_state); break;
    }
  }

  private function submitAdd(array &$form, FormStateInterface $form_state) {
    $mountpointGroupSelection = $form_state->getValue('select_mountpoint_group');
    $mountpointGroup = '';

    if ($mountpointGroupSelection === 'create_new') {
        $mountpointGroup = trim($form_state->getValue('new_mountpoint_group'));
    } else {
        $mountpointGroup = $mountpointGroupSelection;
    }

    $newMountName = trim($form_state->getValue('new_mountpoint_name'));
    $allowedGroups = trim($form_state->getValue('allowed_user_groups_new'));

    if (empty($mountpointGroup) || empty($newMountName) || empty($allowedGroups)) {
      $this->messenger()->addError($this->t('Mountpoint Group, New Mountpoint Name, and Allowed User Groups are required to add.'));
      return;
    }

    $clientMountsData = $this->_readClientMounts();
    
    if (isset($clientMountsData[$newMountName])) {
        $this->messenger()->addError($this->t('Mountpoint @mount already exists.', ['@mount' => $newMountName]));
        return;
    }

    $clientMountsData[$newMountName] = [
        'category' => $mountpointGroup,
        'groups' => array_map('trim', explode(',', $allowedGroups)),
    ];
    
    $this->saveClientMounts($clientMountsData);
    $this->messenger()->addStatus($this->t('Successfully added mountpoint: @mount to group @group', ['@mount' => $newMountName, '@group' => $mountpointGroup]));  
  }
  private function submitDelete(array &$form, FormStateInterface $form_state) { 
    $mountToDelete = $form_state->getValue('mountpoint_to_delete');
    $confirmName = trim($form_state->getValue('confirm_delete_name'));
    
    if (empty($mountToDelete) || $mountToDelete !== $confirmName) {
      $this->messenger()->addError($this->t('Selection and confirmation name must match to delete.'));
      return;
    }
      
    $clientMountsData = $this->_readClientMounts();
    if (isset($clientMountsData[$mountToDelete])) {
      unset($clientMountsData[$mountToDelete]);
      $this->saveClientMounts($clientMountsData);
      $this->messenger()->addStatus($this->t('Successfully deleted mountpoint: @mount', ['@mount' => $mountToDelete]));
    }
  }  
  private function submitUpdateAllowedGroups(array &$form, FormStateInterface $form_state) {
    $mountToEdit = $form_state->getValue('select_mountpoint_to_edit');
    if (empty($mountToEdit)) { $this->messenger()->addError($this->t('Please select a mountpoint to update.')); return; }
    $newGroups = trim($form_state->getValue('allowed_user_groups_edit'));
    $clientMountsData = $this->_readClientMounts();
    $clientMountsData[$mountToEdit] = array_map('trim', explode(',', $newGroups));
    $this->saveClientMounts($clientMountsData);
    $this->messenger()->addStatus($this->t('Allowed groups for @mount have been updated.', ['@mount' => $mountToEdit]));
  }

  private function submitModifyGroups(array &$form, FormStateInterface $form_state) {
    // 1. รับค่าจากฟอร์ม
    $mountToEdit = $form_state->getValue('select_mountpoint_to_edit');
    $action = $form_state->getValue('action');
    $groupsToModifyStr = trim($form_state->getValue('user_groups_to_modify'));

    // 2. ตรวจสอบข้อมูลเบื้องต้น
    if (empty($mountToEdit) || empty($action) || empty($groupsToModifyStr)) {
        $this->messenger()->addError($this->t('Mountpoint, Action, and User Groups to Modify are all required.'));
        return;
    }

    $file = self::CLIENTMOUNTS_FILE;

    // 3. ตรวจสอบว่าไฟล์สามารถอ่านได้
    if (!is_readable($file)) {
        $this->messenger()->addError($this->t('File not readable: @file', ['@file' => $file]));
        return;
    }

    // อ่านไฟล์ทั้งหมดมาเป็น array ทีละบรรทัด
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        $this->messenger()->addError($this->t('Failed to read file: @file', ['@file' => $file]));
        return;
    }

    $mountFound = false;

    // 4. วน Loop เพื่อหาบรรทัดที่ต้องการแก้ไข
    foreach ($lines as $i => &$line) { // ใช้ & เพื่อให้แก้ไขค่าใน array ได้โดยตรง
        $trimmedLine = trim($line);

        // ข้ามบรรทัดว่างและคอมเมนต์
        if (empty($trimmedLine) || strpos($trimmedLine, '#') === 0) {
            continue;
        }

        // ตรวจสอบว่าเป็นบรรทัดของ mountpoint และตรงกับที่เลือกมา
        if (strpos($trimmedLine, $mountToEdit . ':') === 0) {
            $mountFound = true;

            // แยกส่วนชื่อ mountpoint และรายชื่อ groups
            list($mountName, $currentGroupsStr) = explode(':', $trimmedLine, 2);
            
            // แปลงรายชื่อ groups จาก string เป็น array (ตัดช่องว่าง)
            $currentGroups = array_map('trim', explode(',', $currentGroupsStr));
            // ลบค่าว่างที่อาจเกิดจาก comma ติดกัน (เช่น a,,b)
            $currentGroups = array_filter($currentGroups); 
            
            // แปลง groups ที่รับจากฟอร์มเป็น array
            $groupsToModify = array_map('trim', explode(',', $groupsToModifyStr));

            // 5. ดำเนินการตาม Action ที่เลือก
            if ($action === 'add') {
                // รวม array ของ group เดิมกับ group ใหม่ และเอาตัวที่ซ้ำออก
                $newGroups = array_unique(array_merge($currentGroups, $groupsToModify));
                $this->messenger()->addStatus($this->t('Added groups to @mount.', ['@mount' => $mountToEdit]));

            } else { // 'remove'
                // ลบ group ที่ระบุออกจาก array group เดิม
                $newGroups = array_diff($currentGroups, $groupsToModify);
                $this->messenger()->addStatus($this->t('Removed groups from @mount.', ['@mount' => $mountToEdit]));
            }
            
            // 6. สร้างบรรทัดใหม่และอัปเดตใน array $lines
            $line = $mountName . ':' . implode(',', array_values($newGroups));

            // หยุด loop เมื่อเจอและแก้ไขแล้ว
            break;
        }
    }

    // 7. บันทึกการเปลี่ยนแปลงลงไฟล์
    if ($mountFound) {
        $content = implode("\n", $lines);
        // เพิ่ม newline 
        if (!empty($content)) {
            $content .= "\n";
        }
        $this->_writeFileSecurely($file, $content);
        \Drupal::logger('ntrip')->notice('Modified groups for mountpoint: @mount', ['@mount' => $mountToEdit]);
    } else {
        $this->messenger()->addError($this->t('Mountpoint @mount not found in the file.', ['@mount' => $mountToEdit]));
    }
  }

  private function submitRenameGroup(array &$form, FormStateInterface $form_state) {
    $oldGroupName = $form_state->getValue('select_mountpoint_group_to_rename');
    $newGroupName = trim($form_state->getValue('new_mountpoint_group_name'));
    if (empty($oldGroupName) || empty($newGroupName)) { $this->messenger()->addError($this->t('Old and new group names are required to rename.')); return; }
    if ($oldGroupName === $newGroupName) { $this->messenger()->addWarning($this->t('New group name is the same as the old one.')); return; }

    // 1. Rename in groups.aut
    $groupsData = $this->_readGroups();
    if (isset($groupsData[$oldGroupName])) {
      $groupsData[$newGroupName] = $groupsData[$oldGroupName];
      unset($groupsData[$oldGroupName]);
      $this->saveGroups($groupsData);
      $this->messenger()->addStatus($this->t('Renamed group @old to @new in groups.aut.', ['@old' => $oldGroupName, '@new' => $newGroupName]));
    }

    // 2. Rename in clientmounts.aut
    $clientMountsData = $this->_readClientMounts();
    foreach ($clientMountsData as $mount => &$groups) {
      $key = array_search($oldGroupName, $groups);
      if ($key !== false) {
        $groups[$key] = $newGroupName;
      }
    }
    $this->saveClientMounts($clientMountsData);
    $this->messenger()->addStatus($this->t('Updated group name @old to @new in all mountpoints.', ['@old' => $oldGroupName, '@new' => $newGroupName]));
    $this->messenger()->addWarning($this->t('IMPORTANT: You may need to manually update the group name in users.aut as well.'));
  }

  // ============== FILE SAVING HELPERS ==============
  
  private function saveClientMounts(array $data) {
    $content = "";
    foreach ($data as $mount => $groups) {
      $content .= $mount . ';' . implode(';', $groups) . "\n";
    }
    $this->_writeFileSecurely(self::CLIENTMOUNTS_FILE, $content);
  }

  private function saveGroups(array $data) {
    $content = "";
    foreach ($data as $groupName => $users) {
      $content .= $groupName . ':' . implode(',', $users) . "\n";
    }
    $this->_writeFileSecurely(self::GROUPS_FILE, $content);
  }
}