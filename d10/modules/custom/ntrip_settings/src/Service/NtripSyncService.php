public function createUser($username, $password, $group = 'default') {

  // -------- USERS FILE --------
  $usersData = [];

  if (file_exists($this->usersFile)) {
    $lines = file($this->usersFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
      if (strpos($line, ':') !== false) {
        list($u, $p) = explode(':', $line, 2);
        $usersData[trim($u)] = trim($p);
      }
    }
  }

  $usersData[$username] = $password;

  $userContent = "# User authentication file.\n";
  foreach ($usersData as $u => $p) {
    $userContent .= "$u:$p\n";
  }

  file_put_contents($this->usersFile, $userContent);


  // -------- GROUPS FILE --------
  $groupsData = [];

  if (file_exists($this->groupsFile)) {
    $lines = file($this->groupsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
      if (strpos($line, ':') !== false) {
        list($g, $usersStr) = explode(':', $line, 2);
        $groupsData[trim($g)] = !empty(trim($usersStr))
          ? array_map('trim', explode(',', $usersStr))
          : [];
      }
    }
  }

  if (!isset($groupsData[$group])) {
    $groupsData[$group] = [];
  }

  if (!in_array($username, $groupsData[$group])) {
    $groupsData[$group][] = $username;
  }

  $groupContent = "# Group authentication file.\n";
  foreach ($groupsData as $g => $users) {
    $groupContent .= $g . ':' . implode(',', $users) . "\n";
  }

  file_put_contents($this->groupsFile, $groupContent);
}