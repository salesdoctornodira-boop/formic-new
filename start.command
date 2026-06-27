#!/bin/bash
# CCS Platform — запуск локального сервера
# Двойной клик по этому файлу запустит сервер и откроет браузер.
# PHP устанавливать НЕ нужно: если его нет в системе, скрипт сам подложит
# портативный PHP в ./bin/php (один раз, ~10 МБ, без Homebrew и прав админа).

# Переходим в папку проекта (туда, где лежит этот файл)
cd "$(dirname "$0")"

echo "========================================"
echo "  CCS Platform — локальный сервер"
echo "========================================"
echo ""

# ── Находим PHP: 1) системный  2) портативный ./bin/php  3) качаем портативный ──
PHP=""
if command -v php &> /dev/null; then
    PHP="php"
elif [ -x "./bin/php" ]; then
    PHP="./bin/php"
else
    echo "PHP в системе не найден — скачиваю портативную версию (один раз, ~10 МБ)…"
    ARCH="$(uname -m)"
    case "$ARCH" in
        arm64|aarch64) URL="https://dl.static-php.dev/static-php-cli/common/php-8.4.8-cli-macos-aarch64.tar.gz" ;;
        x86_64)        URL="https://dl.static-php.dev/static-php-cli/common/php-8.4.8-cli-macos-x86_64.tar.gz" ;;
        *) echo "❌ Неизвестная архитектура: $ARCH. Установите PHP вручную (brew install php)."; read -p "Enter для выхода…"; exit 1 ;;
    esac
    TMPD="$(mktemp -d)"
    if curl -fsSL "$URL" -o "$TMPD/php.tgz" && tar -xzf "$TMPD/php.tgz" -C "$TMPD"; then
        FOUND="$(find "$TMPD" -type f -name php | head -1)"
        if [ -n "$FOUND" ]; then
            mkdir -p bin && mv "$FOUND" bin/php && chmod +x bin/php
            PHP="./bin/php"
            echo "✅ Портативный PHP установлен в ./bin/php"
        fi
    fi
    rm -rf "$TMPD"
    if [ -z "$PHP" ]; then
        echo "❌ Не удалось скачать PHP. Проверьте интернет или установите PHP вручную:"
        echo "   /bin/bash -c \"\$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)\" && brew install php"
        read -p "Нажмите Enter для выхода…"; exit 1
    fi
fi

PHP_VERSION="$($PHP -r 'echo PHP_VERSION;' 2>/dev/null)"
echo "✅ PHP $PHP_VERSION найден ($PHP)"

# Проверяем pdo_sqlite (без него база не работает)
if $PHP -r "new PDO('sqlite::memory:');" &> /dev/null; then
    echo "✅ pdo_sqlite работает"
else
    echo "❌ pdo_sqlite недоступен в этом PHP."
    echo "   Удалите ./bin/php и запустите снова (скачается рабочая сборка), либо: brew install php"
    read -p "Нажмите Enter для выхода…"; exit 1
fi

echo ""
echo "🚀 Запускаю сервер на http://localhost:8080"
echo "   Закройте это окно, чтобы остановить сервер."
echo ""
echo "   Входы:"
echo "     • Сотрудник     — выбрать проект+отдел (без пароля)"
echo "     • Руководитель  — пароль выдаёт администратор"
echo "     • Admin         — логин/пароль выдаёт администратор"
echo "     • SuperAdmin    — логин/пароль выдаёт администратор (Конструктор сотрудников здесь)"
echo ""

# Открываем браузер через 1.5 секунды
(sleep 1.5 && open "http://localhost:8080") &

# Запускаем PHP сервер (докрут = папка проекта)
$PHP -S localhost:8080

echo ""
read -p "Сервер остановлен. Нажмите Enter для выхода…"
