FROM python:3.9-slim

# ติดตั้ง dependencies ที่จำเป็นสำหรับระบบ
RUN apt-get update && apt-get install -y \
    gcc \
    python3-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# ติดตั้ง Python libraries
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# ก๊อปปี้สคริปต์เข้าไป
COPY . .

# เปิด Port 5000 ตามที่สคริปต์ตั้งไว้
EXPOSE 5000

# สั่งรันสคริปต์
CMD ["python", "gnss_bridge.py"]
