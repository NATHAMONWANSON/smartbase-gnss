<?php

namespace Drupal\ntrip_user_manager\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;

class UserManagerForm extends FormBase {

  const CONFIG_PATH = '/etc/ntripcaster/';
  const GROUPS_FILE = self::CONFIG_PATH . 'groups.aut';
  const USERS_FILE = self::CONFIG_PATH . 'users.aut';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ntrip_user_manager_config_form';
  }

  // ============== HELPER FUNCTIONS ==============

  private function _readGroups() {
    $groups = [];
    if (is_readable(self::GROUPS_FILE)) {
      $lines = file(self::GROUPS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        if (strpos($line, ':') !== false) {
          list($groupName, $usersStr) = explode(':', $line, 2);
          $users = !empty(trim($usersStr)) ? explode(',', $usersStr) : [];
          $groups[trim($groupName)] = array_map('trim', $users);
        }
      }
    }
    return $groups;
  }

  private function _readUsers() {
    $users = [];
    if (is_readable(self::USERS_FILE)) {
      $lines = file(self::USERS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        if (strpos($line, ':') !== false) {
          list($userName, $password) = explode(':', $line, 2);
          // ใช้ trim เพื่อป้องกันเว้นวรรคหรือ \r ที่อาจทำให้หา key ไม่เจอ
          $users[trim($userName)] = trim($password);
        }
      }
    }
    return $users;
  }

  private function _saveGroups(array $groupsData) {
    $content = "# Group authentication file.\n# Syntax: <GROUP>:<USER1>,<USER2>\n\n";
    ksort($groupsData);
    foreach ($groupsData as $groupName => $users) {
      $content .= $groupName . ':' . implode(',', $users) . "\n";
    }
    $this->_writeFileSecurely(self::GROUPS_FILE, $content);
  }

  private function _saveUsers(array $usersData) {
    $content = "# User authentication file.\n# Syntax: <USER>:<PASSWORD>\n\n";
    ksort($usersData);
    foreach ($usersData as $userName => $password) {
      $content .= $userName . ':' . $password . "\n";
    }
    $this->_writeFileSecurely(self::USERS_FILE, $content);
  }

  private function _writeFileSecurely($file_path, $content) {
    $escaped_content = escapeshellarg($content);
    $command = "echo " . $escaped_content . " | sudo /usr/bin/tee " . $file_path;
    shell_exec($command . ' > /dev/null 2>&1');
  }

  // ============== FORM BUILDING ==============

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $groupsData = $this->_readGroups();
    $usersData = $this->_readUsers();

    $form['current_state'] = [
      '#type' => 'details',
      '#title' => $this->t('Current Groups & Users'),
      '#open' => TRUE,
    ];

    // แก้ไข: กำหนด Key ของ Header ให้ชัดเจน
    $header = [
      'group_col' => $this->t('Group Name'),
      'member_col' => $this->t('Members'),
      'password_col' => $this->t('Passwords'),
    ];

    $rows = [];
    foreach ($groupsData as $groupName => $users) {
      $member_list = [];
      $pass_list = [];

      if (!empty($users)) {
        foreach ($users as $username) {
          $member_list[] = $username;
          // ตรวจสอบ password จากไฟล์ users.aut
          $pass_list[] = isset($usersData[$username]) ? $usersData[$username] : '<em>(Not found)</em>';
        }
        
        // ใช้ Markup::create เพื่อให้แสดงผล <br> ได้อย่างถูกต้อง
        $members_display = Markup::create(implode('<br>', $member_list));
        $passwords_display = Markup::create(implode('<br>', $pass_list));
      } 
      else {
        $members_display = Markup::create('<em>(No members)</em>');
        $passwords_display = '-';
      }

      // แก้ไข: ใส่คีย์ให้ตรงกับ Header
      $rows[] = [
        'group_col' => $groupName,
        'member_col' => $members_display,
        'password_col' => $passwords_display,
      ];
    }

    $form['current_state']['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No groups found.'),
    ];

    // --- ส่วนจัดการ Group ---
    $form['group_management'] = [
      '#type' => 'details',
      '#title' => $this->t('Group Management'),
      '#open' => FALSE,
    ];

    $form['group_management']['new_group_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('New Group Name'),
      '#description' => $this->t('Enter a name for the new group.'),
    ];
    $form['group_management']['submit_add_group'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create Group'),
      '#submit' => ['::submitAddGroup'],
      '#limit_validation_errors' => [['new_group_name']],
    ];

    $form['group_management']['hr1'] = ['#markup' => '<hr>'];

    $groupOptions = !empty($groupsData) ? ['' => '- Select Group to Delete -'] + array_combine(array_keys($groupsData), array_keys($groupsData)) : ['' => '- No groups -'];
    $form['group_management']['group_to_delete'] = [
      '#type' => 'select',
      '#title' => $this->t('Select Group to Delete'),
      '#options' => $groupOptions,
    ];
    $form['group_management']['confirm_delete_group_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Confirm Deletion (Type group name)'),
      '#description' => $this->t('Type the group name exactly to confirm deletion. This will also delete all users within this group from users.aut.'),
    ];
    $form['group_management']['submit_delete_group'] = [
      '#type' => 'submit',
      '#value' => $this->t('Delete Group'),
      '#submit' => ['::submitDeleteGroup'],
      '#limit_validation_errors' => [['group_to_delete'], ['confirm_delete_group_text']],
    ];

    // --- ส่วนจัดการ User ---
    $form['user_management'] = [
      '#type' => 'details',
      '#title' => $this->t('User Management'),
      '#open' => TRUE,
    ];
    
    $form['user_management']['select_group_for_new_user'] = [
      '#type' => 'select',
      '#title' => $this->t('Add User to Group'),
      '#options' => $groupOptions,
    ];
    $form['user_management']['new_username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('New Username'),
    ];
    $form['user_management']['new_password'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Password'),
    ];
    $form['user_management']['submit_add_user'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create User'),
      '#submit' => ['::submitAddUser'],
       '#limit_validation_errors' => [['select_group_for_new_user'], ['new_username'], ['new_password']],
    ];

    $form['user_management']['hr2'] = ['#markup' => '<hr>'];

    // ลบ User
    $userOptions = !empty($usersData) ? ['' => '- Select User to Delete -'] + array_combine(array_keys($usersData), array_keys($usersData)) : ['' => '- No users -'];
    $form['user_management']['user_to_delete'] = [
      '#type' => 'select',
      '#title' => $this->t('Select User to Delete'),
      '#options' => $userOptions,
    ];
    $form['user_management']['confirm_delete_user_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Confirm Deletion (Type username)'),
      '#description' => $this->t('Type the username exactly to confirm deletion.'),
    ];
    $form['user_management']['submit_delete_user'] = [
      '#type' => 'submit',
      '#value' => $this->t('Delete User'),
      '#submit' => ['::submitDeleteUser'],
      '#limit_validation_errors' => [['user_to_delete'],['confirm_delete_user_text']],
    ];

    return $form;
  }
  
  // ============== SUBMIT HANDLERS ==============

  public function submitForm(array &$form, FormStateInterface $form_state) {}

  public function submitAddGroup(array &$form, FormStateInterface $form_state) {
    $newGroupName = trim($form_state->getValue('new_group_name'));
    if (empty($newGroupName)) return;

    $groupsData = $this->_readGroups();
    if (isset($groupsData[$newGroupName])) {
      $this->messenger()->addError($this->t('Group already exists.'));
      return;
    }

    $groupsData[$newGroupName] = []; 
    $this->_saveGroups($groupsData);
    $this->messenger()->addStatus($this->t('Group created.'));
  }

  public function submitDeleteGroup(array &$form, FormStateInterface $form_state) {
    $groupToDelete = $form_state->getValue('group_to_delete');
    $confirmText = trim($form_state->getValue('confirm_delete_group_text'));

    if ($groupToDelete !== $confirmText) {
      $this->messenger()->addError($this->t('Confirmation failed.'));
      return;
    }

    $groupsData = $this->_readGroups();
    $usersData = $this->_readUsers();

    if (isset($groupsData[$groupToDelete])) {
      foreach ($groupsData[$groupToDelete] as $user) {
        unset($usersData[$user]);
      }
      unset($groupsData[$groupToDelete]);
      $this->_saveGroups($groupsData);
      $this->_saveUsers($usersData);
      $this->messenger()->addStatus($this->t('Group and its users deleted.'));
    }
  }

  public function submitAddUser(array &$form, FormStateInterface $form_state) {
    $groupName = $form_state->getValue('select_group_for_new_user');
    $newUsername = trim($form_state->getValue('new_username'));
    $newPassword = trim($form_state->getValue('new_password'));

    if (empty($groupName) || empty($newUsername) || empty($newPassword)) return;

    $groupsData = $this->_readGroups();
    $usersData = $this->_readUsers();

    $usersData[$newUsername] = $newPassword;
    if (!in_array($newUsername, $groupsData[$groupName])) {
        $groupsData[$groupName][] = $newUsername;
    }
    
    $this->_saveUsers($usersData);
    $this->_saveGroups($groupsData);
    $this->messenger()->addStatus($this->t('User "@user" added to "@group".', ['@user' => $newUsername, '@group' => $groupName]));
  }

  public function submitDeleteUser(array &$form, FormStateInterface $form_state) {
    $userToDelete = $form_state->getValue('user_to_delete');
    $confirmText = trim($form_state->getValue('confirm_delete_user_text'));

    if ($userToDelete !== $confirmText) {
      $this->messenger()->addError($this->t('Confirmation failed.'));
      return;
    }

    $groupsData = $this->_readGroups();
    $usersData = $this->_readUsers();

    if (isset($usersData[$userToDelete])) {
      unset($usersData[$userToDelete]);
      foreach ($groupsData as $name => &$users) {
        if (($key = array_search($userToDelete, $users)) !== false) {
          unset($users[$key]);
        }
      }
      $this->_saveUsers($usersData);
      $this->_saveGroups($groupsData);
      $this->messenger()->addStatus($this->t('User deleted.'));
    }
  }
}
