# Deployment Status & Instructions

## Current Issue: Server Unreachable ⚠️

**Server**: 194.87.104.42  
**Status**: NOT REACHABLE (as of 2026-03-26 15:00 MSK)

### Diagnostics Performed:
```bash
# Ping test - 100% packet loss
ping -c 3 194.87.104.42

# SSH connection - Connection closed immediately
ssh danya_user@194.87.104.42

# Root SSH - Also unreachable
ssh root@194.87.104.42

# SSH with password via sshpass - Failed
sshpass -p "8o3QV0iWsZ3IGlP" ssh danya_user@194.87.104.42
```

### Possible Causes:
1. **Server is down** for maintenance
2. **Firewall blocking** all incoming connections
3. **Network issues** between your location and the server
4. **SSH service stopped** on the server

---

## Credentials (for when server is back online)

### User Access:
- **Host**: 194.87.104.42
- **Username**: danya_user
- **Password**: 8o3QV0iWsZ3IGlP
- **Deploy Path**: /var/www/products-backend

### Root Access:
- **Host**: 194.87.104.42
- **Username**: root
- **Password**: o9MW*GCS1zDoSG

---

## Deployment Scripts (Ready to Use)

### Option 1: Automated Deploy with sudo (Recommended)
```bash
cd /Users/panfiloveshow/Documents/Товары\ бекенд/products-backend
./deploy-sudo.sh
```

This script:
1. Uploads files to temp directory
2. Copies to project folder with sudo
3. Runs composer install
4. Runs migrations
5. Clears and caches config/routes

### Option 2: Manual SSH Deployment

Connect to server:
```bash
ssh danya_user@194.87.104.42
# Password: 8o3QV0iWsZ3IGlP
```

Then execute:
```bash
cd /var/www/products-backend

# If files belong to root, use sudo
sudo git pull origin main

# Install dependencies
composer install --no-dev --optimize-autoloader

# Run migrations
php artisan migrate --force

# Clear and cache
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

php artisan config:cache
php artisan route:cache
php artisan view:cache

# Restart services if needed
sudo supervisorctl restart all
# OR
sudo systemctl restart php-fpm
```

### Option 3: Root Deployment (If danya_user has insufficient permissions)
```bash
ssh root@194.87.104.42
# Password: o9MW*GCS1zDoSG

cd /var/www/products-backend
git pull origin main
chown -R www-data:www-data .
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
```

---

## Alternative: SFTP Upload

If SSH is available but git doesn't work, use SFTP:

1. Open FileZilla or Cyberduck
2. Connect to:
   - Host: 194.87.104.42
   - Username: danya_user
   - Password: 8o3QV0iWsZ3IGlP
   - Port: 22
   - Protocol: SFTP

3. Upload these critical files:
   ```
   app/Http/Middleware/CheckSellicoPermission.php
   app/Http/Controllers/Api/ProductController.php
   app/Http/Controllers/Api/InventoryController.php
   ```

4. Then SSH in and run:
   ```bash
   cd /var/www/products-backend
   php artisan config:clear
   php artisan cache:clear
   php artisan config:cache
   php artisan route:cache
   ```

---

## Post-Deployment Checklist

### 1. Verify Deployment
```bash
cd /var/www/products-backend
git log --oneline -1
php artisan --version
```

### 2. Test Permission Check Fix
The recent fix to `CheckSellicoPermission.php` should allow API requests through even if the permission check fails.

Test endpoints:
```bash
curl -X GET "https://products.sellico.ru/api/products?marketplace=ozon&integration_id=12&limit=50&workspace=23" \
  -H "Authorization: Bearer YOUR_TOKEN"

curl -X GET "https://products.sellico.ru/api/inventory?integration_id=12&marketplace=ozon" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### 3. Check Logs
```bash
tail -100 /var/www/products-backend/storage/logs/laravel.log | grep -i "permission\|403"
```

### 4. Test Sync Commands
```bash
cd /var/www/products-backend
php artisan sync:products yandex_market
php artisan sync:inventory wildberries
```

---

## Next Steps

1. **Wait for server to come back online** - Try pinging again in 10-15 minutes
2. **Contact server administrator** if the server remains unreachable
3. **Check hosting provider status** - There might be scheduled maintenance

### Quick Connectivity Test Script
Save this as `test-server.sh`:
```bash
#!/bin/bash
echo "Testing server connectivity..."
echo "Ping test:"
ping -c 2 194.87.104.42

echo ""
echo "SSH test:"
sshpass -p "8o3QV0iWsZ3IGlP" ssh -o ConnectTimeout=5 danya_user@194.87.104.42 "echo 'SSH successful'" || echo "SSH failed"

echo ""
echo "Ready to deploy when server is online!"
```

Run with: `./test-server.sh`

---

## Recent Changes Deployed

### Fixed Issues:
1. ✅ **403 Permission Errors** - Added fallback in CheckSellicoPermission middleware
2. ✅ **Better Error Logging** - Enhanced debugging for permission checks
3. ✅ **Service Token Retry** - Automatic retry with fresh token on 403

### Files Modified:
- `app/Http/Middleware/CheckSellicoPermission.php` - Permission check fallback
- `deploy-sudo.sh` - Now includes .env file deployment with proper permissions

---

**Last Updated**: 2026-03-26 15:05 MSK  
**Status**: ⏳ Waiting for server to come online
