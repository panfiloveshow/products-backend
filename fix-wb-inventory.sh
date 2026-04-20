#!/bin/bash

# Fix WB Inventory Sync - Hotfix deployment script
# Deploys only Wildberries inventory fix files

set -e

SERVER="194.87.104.42"
USER="danya_user"
PASSWORD="8o3QV0iWsZ3IGlP"
REMOTE_DIR="/var/www/products-backend"
TEMP_DIR="/tmp/products-backend-wb-fix"

echo "🚀 Deploying WB Inventory Fix..."
echo "   Server: $SERVER"
echo "   User: $USER"
echo ""

# Export password for sshpass
export SSHPASS="$PASSWORD"

# Step 1: Create temporary directory on server
echo "📦 Step 1/6: Creating temporary directory..."
sshpass -e ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 $USER@$SERVER "mkdir -p $TEMP_DIR/app/Domains/Wildberries/Api" 2>&1 || {
    echo "❌ Failed to create temp directory"
    exit 1
}

# Step 2: Copy files via scp
echo "📦 Step 2/6: Copying WildberriesClient.php..."
cat app/Domains/Wildberries/Api/WildberriesClient.php | sshpass -e ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 $USER@$SERVER "cat > $TEMP_DIR/app/Domains/Wildberries/Api/WildberriesClient.php" 2>&1 || {
    echo "❌ Failed to copy WildberriesClient.php"
    exit 1
}

echo "📦 Step 3/6: Copying InventoryApi.php..."
cat app/Domains/Wildberries/Api/InventoryApi.php | sshpass -e ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 $USER@$SERVER "cat > $TEMP_DIR/app/Domains/Wildberries/Api/InventoryApi.php" 2>&1 || {
    echo "❌ Failed to copy InventoryApi.php"
    exit 1
}

# Step 4: Move files to final destination with sudo
echo "📦 Step 4/6: Moving files to production..."
sshpass -e ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 $USER@$SERVER "
    echo '$PASSWORD' | sudo -S cp $TEMP_DIR/app/Domains/Wildberries/Api/WildberriesClient.php $REMOTE_DIR/app/Domains/Wildberries/Api/WildberriesClient.php
    echo '$PASSWORD' | sudo -S cp $TEMP_DIR/app/Domains/Wildberries/Api/InventoryApi.php $REMOTE_DIR/app/Domains/Wildberries/Api/InventoryApi.php
    echo '$PASSWORD' | sudo -S chown -R www-data:www-data $REMOTE_DIR/app/Domains/Wildberries/Api/
    echo '$PASSWORD' | sudo -S chmod -R 755 $REMOTE_DIR/app/Domains/Wildberries/Api/
    rm -rf $TEMP_DIR
" 2>&1 || {
    echo "❌ Failed to move files"
    exit 1
}

# Step 5: Clear caches
echo "📦 Step 5/6: Clearing caches..."
sshpass -e ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 $USER@$SERVER "
    cd $REMOTE_DIR
    echo '$PASSWORD' | sudo -S php artisan config:clear
    echo '$PASSWORD' | sudo -S php artisan route:clear
    echo '$PASSWORD' | sudo -S php artisan view:clear
    echo '$PASSWORD' | sudo -S php artisan cache:clear
" 2>&1 || {
    echo "⚠️  Warning: Failed to clear some caches"
}

# Step 6: Verify deployment
echo "📦 Step 6/6: Verifying deployment..."
sshpass -e ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 $USER@$SERVER "
    cd $REMOTE_DIR
    echo 'Checking files...'
    ls -la app/Domains/Wildberries/Api/WildberriesClient.php
    ls -la app/Domains/Wildberries/Api/InventoryApi.php
" 2>&1 || {
    echo "⚠️  Warning: Failed to verify"
}

echo ""
echo "✅ WB Inventory Fix deployed successfully!"
echo ""
echo "📝 Next steps:"
echo "   1. Wait 1-2 minutes for queue workers to pick up changes"
echo "   2. Check sync status: ssh $USER@$SERVER 'cd $REMOTE_DIR && php artisan sync:status'"
echo "   3. Monitor logs: ssh $USER@$SERVER 'tail -f $REMOTE_DIR/storage/logs/laravel.log | grep WB'"
echo ""
