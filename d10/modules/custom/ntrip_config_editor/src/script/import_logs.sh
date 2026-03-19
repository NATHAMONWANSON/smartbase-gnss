#!/bin/bash
# -------------------------------
# Script: import_logs.sh
# ใช้รวมไฟล์ access-*.log และ import เข้าฐาน MySQL
# -------------------------------

# กำหนด path และไฟล์
LOG_DIR="/var/log/ntripcaster"
ALL_LOG="$LOG_DIR/all_access.log"

# กำหนดค่า MySQL
DB_NAME="d10"
DB_USER="navicat"
DB_PASS="StrongPassword123!"
DB_TABLE="logs"

# 1) รวมไฟล์ โดยให้ header จากไฟล์แรกเท่านั้น
cd "$LOG_DIR" || exit
echo "[INFO] Combining log files..."
head -n 1 access-*.log | head -n 1 > "$ALL_LOG"
tail -n +2 -q access-*.log >> "$ALL_LOG"

# 2) Import เข้า MySQL
echo "[INFO] Importing into MySQL..."
mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" <<EOF
LOAD DATA LOCAL INFILE '$ALL_LOG'
INTO TABLE $DB_TABLE
FIELDS TERMINATED BY ','
LINES TERMINATED BY '\n'
IGNORE 1 ROWS
(@log_date, log_time, user, ip, station, client, seconds, bytes)
SET log_date = STR_TO_DATE(@log_date, '%d/%b/%Y');
EOF

echo "[INFO] Import finished!"
