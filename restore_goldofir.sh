#!/bin/bash
# Script para restaurar os arquivos customizados do Goldofir no container Docker
# Execute: bash /root/restore_goldofir.sh

CTR=$(docker ps -q --filter name=portalgold_app)
if [ -z "$CTR" ]; then
    echo "ERRO: container portalgold_app não encontrado"
    exit 1
fi
echo "Container: $CTR"

# Criar diretórios necessários
docker exec $CTR sh -c 'mkdir -p /var/www/app/Http/Controllers/Admin /var/www/app/Services /var/www/resources/views/admin/email /var/www/resources/views/admin/users'

# Rotas
docker cp /tmp/web_live.php $CTR:/var/www/routes/web.php && echo "✓ routes/web.php"

# Controllers
docker cp /root/restore-files/EmailQueueController_live.php $CTR:/var/www/app/Http/Controllers/Admin/EmailQueueController.php && echo "✓ Admin/EmailQueueController.php"
docker cp /tmp/SmtpService_live.php $CTR:/var/www/app/Services/SmtpService.php && echo "✓ Services/SmtpService.php"
docker cp /root/restore-files/UsersCtrl_current.php $CTR:/var/www/app/Http/Controllers/UsersController.php && echo "✓ UsersController.php"
docker cp /root/restore-files/PC_current.php $CTR:/var/www/app/Http/Controllers/ProfileController.php && echo "✓ ProfileController.php"

# Model
docker cp /tmp/User_live.php $CTR:/var/www/app/User.php && echo "✓ User.php"

# Views - layout
docker cp /tmp/sidebar_live.blade.php $CTR:/var/www/resources/views/layouts/sidebar.blade.php && echo "✓ layouts/sidebar.blade.php"

# Views - admin home (proper admin dashboard, queries DB directly)
docker cp /tmp/admin_home_fixed.blade.php $CTR:/var/www/resources/views/admin/home.blade.php && echo "✓ admin/home.blade.php"

# Views - user home (home_live is the USER home, goes in user/home.blade.php)
docker cp /tmp/home_live.blade.php $CTR:/var/www/resources/views/user/home.blade.php && echo "✓ user/home.blade.php"
docker cp /tmp/campaigns_live.blade.php $CTR:/var/www/resources/views/admin/email/campaigns.blade.php && echo "✓ admin/email/campaigns.blade.php"
docker cp /root/restore-files/email_create_current.blade.php $CTR:/var/www/resources/views/admin/email/create.blade.php && echo "✓ admin/email/create.blade.php"
docker cp /tmp/email_show.blade.php $CTR:/var/www/resources/views/admin/email/show.blade.php && echo "✓ admin/email/show.blade.php"
docker cp /tmp/verified_users_live.blade.php $CTR:/var/www/resources/views/admin/verified.blade.php && echo "✓ admin/verified.blade.php"
docker cp /tmp/single_admin_live.php $CTR:/var/www/resources/views/admin/users/single.blade.php && echo "✓ admin/users/single.blade.php"

# Views - user
docker cp /root/restore-files/verif.blade.php $CTR:/var/www/resources/views/user/verification.blade.php && echo "✓ user/verification.blade.php"

# Middleware e helpers
docker cp /tmp/CheckStatus.php $CTR:/var/www/app/Http/Middleware/CheckStatus.php && echo "✓ Middleware/CheckStatus.php"
docker cp /tmp/helpers_fixed.php $CTR:/var/www/app/Http/Helpers/helpers.php && echo "✓ helpers.php"

# PHP upload limits (30MB for KYC photos)
docker exec $CTR sh -c 'cat > /usr/local/etc/php/conf.d/uploads.ini << EOF
upload_max_filesize=30M
post_max_size=35M
memory_limit=512M
EOF' && echo "✓ uploads.ini"

# Install system tools needed for KYC processing
docker exec $CTR sh -c 'apt-get install -y ghostscript wkhtmltopdf 2>/dev/null | tail -3' && echo "✓ ghostscript + wkhtmltopdf installed"

# ImageMagick PDF policy — allow read/write (needed for PDF → JPEG conversion)
docker exec $CTR sh -c 'sed -i "s|rights=\"none\" pattern=\"PDF\"|rights=\"read|write\" pattern=\"PDF\"|g" /etc/ImageMagick-6/policy.xml 2>/dev/null; sed -i "s|rights=\"none\" pattern=\"PS\"|rights=\"read|write\" pattern=\"PS\"|g" /etc/ImageMagick-6/policy.xml 2>/dev/null; sed -i "s|rights=\"none\" pattern=\"EPS\"|rights=\"read|write\" pattern=\"EPS\"|g" /etc/ImageMagick-6/policy.xml 2>/dev/null' && echo "✓ ImageMagick PDF policy updated"

# AI Verification Service (with expiry check + doc_expired field)
docker cp /root/restore-files/AIVerif.php $CTR:/var/www/app/Services/AIVerificationService.php && echo "✓ AIVerificationService.php"

# Contract PDF blade (stamp says GOLDOFIR, not GolPay)
docker cp /root/restore-files/contract_live.blade.php $CTR:/var/www/resources/views/pdf/contract.blade.php && echo "✓ pdf/contract.blade.php"

# Vendor libs lost on container recreation (kornrunner = on-chain FGOL signing; dompdf etc.)
for d in barryvdh dompdf genkgo kornrunner masterminds paragonie sabberworm; do
    docker cp /root/vendor-backup/$d $CTR:/var/www/vendor/ && docker exec $CTR chown -R www-data:www-data /var/www/vendor/$d
done
for f in autoload_classmap.php autoload_files.php autoload_namespaces.php autoload_psr4.php autoload_static.php installed.json installed.php; do
    docker cp /root/vendor-backup/composer/$f $CTR:/var/www/vendor/composer/$f
done
echo "✓ vendor libs (kornrunner, dompdf, ...)"

# CDPService (RPC fallbacks + tolerant receipt wait)
docker cp /root/vendor-backup/CDPService_live.php $CTR:/var/www/app/Services/CDPService.php && echo "✓ Services/CDPService.php"

# FGOLTransferService (on-chain balances batch) + admin verified-users list
docker cp /root/vendor-backup/FGOLTransferService_live.php $CTR:/var/www/app/Services/FGOLTransferService.php && echo "✓ Services/FGOLTransferService.php"
docker cp /root/vendor-backup/verified_admin.blade.php $CTR:/var/www/resources/views/admin/users/verified.blade.php && echo "✓ admin/users/verified.blade.php"

# User layout (banner for verified users without a wallet)
docker cp /root/vendor-backup/user_layout_live.blade.php $CTR:/var/www/resources/views/layouts/user.blade.php && echo "✓ layouts/user.blade.php"

# CDP wallet bundle (needed by the wallet-creation step; 404 here = spinner stuck forever)
docker cp /root/cdp-backup/cdp-wallet.bundle.js $CTR:/var/www/assets/cdp-wallet.bundle.js && echo "✓ assets/cdp-wallet.bundle.js"

# Limpar caches
docker exec $CTR sh -c 'cd /var/www && php artisan view:clear && php artisan route:clear && php artisan cache:clear 2>&1'

# Reload PHP-FPM
docker exec $CTR sh -c '
for p in /proc/[0-9]*/cmdline; do
    c=$(cat "$p" 2>/dev/null | tr "\0" " ")
    case "$c" in
        *php-fpm:\ master*)
            PID=$(dirname $p | sed "s|/proc/||")
            kill -USR2 $PID 2>/dev/null && echo "✓ PHP-FPM reloaded (PID $PID)"
            ;;
    esac
done
'

echo ""
echo "=== Restauração concluída ==="
