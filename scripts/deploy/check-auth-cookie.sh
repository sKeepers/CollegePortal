#!/usr/bin/env bash
# Проверка контура авторизации после SEC-002: токен в httpOnly cookie, изменения
# требуют признака CSRF, истёкшая сессия не пускает.
#
# Запускать на стенде, где лежит backend/.env: пароль служебных учётных записей
# берётся оттуда и в вывод не попадает.
#
#   bash scripts/deploy/check-auth-cookie.sh [https://127.0.0.1:5443/api] [director@local]
#
# Ожидаемый результат целиком — в docs/SEC_002_BROWSER_CHECK.md.
set -u

BASE="${1:-https://127.0.0.1:5443/api}"
LOGIN="${2:-director@local}"
ENV_FILE="${ENV_FILE:-backend/.env}"

if [ ! -f "$ENV_FILE" ]; then
  echo "Не найден $ENV_FILE — запускайте из корня установки или задайте ENV_FILE." >&2
  exit 1
fi

PASSWORD=$(grep '^DEMO_USER_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)
if [ -z "$PASSWORD" ]; then
  echo "В $ENV_FILE нет DEMO_USER_PASSWORD." >&2
  exit 1
fi

JAR=$(mktemp)
trap 'rm -f "$JAR"' EXIT
fail=0

code() { curl -sk -b "$JAR" -o /dev/null -w '%{http_code}' "$@"; }
# Без выравнивания по ширине: printf считает байты, а не символы, и на кириллице
# колонки разъезжаются.
check() { # описание ожидание фактическое
  if [ "$2" = "$3" ]; then echo "  ок — $1: $3"
  else echo "  СБОЙ — $1: ожидалось $2, получено $3"; fail=1; fi
}

echo "Контур авторизации: $BASE"

BODY=$(curl -sk -c "$JAR" -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
  -d "{\"login\":\"$LOGIN\",\"password\":\"$PASSWORD\",\"staySignedIn\":true}")
CSRF=$(grep 'cp_csrf' "$JAR" | awk '{print $7}')

check "токена нет в теле ответа"        "0" "$(printf '%s' "$BODY" | grep -c '"token":')"
check "cp_session помечена HttpOnly"    "1" "$(grep -c '^#HttpOnly_.*cp_session' "$JAR")"
check "cp_csrf доступна из JavaScript"  "0" "$(grep -c '^#HttpOnly_.*cp_csrf' "$JAR")"
check "чтение по cookie"                "200" "$(code "$BASE/auth/me")"
check "повторное чтение"                "200" "$(code "$BASE/auth/me")"
check "изменение без признака CSRF"     "419" "$(code -X POST "$BASE/auth/logout")"
check "изменение с чужим признаком"     "419" "$(code -X POST -H 'X-CSRF-Token: чужой' "$BASE/auth/logout")"
check "выход с верным признаком"        "200" "$(code -X POST -H "X-CSRF-Token: $CSRF" "$BASE/auth/logout")"
check "после выхода не пускает"         "401" "$(code "$BASE/auth/me")"

if [ "$fail" -eq 0 ]; then echo "Контур авторизации в порядке."; else echo "Есть расхождения, смотрите строки СБОЙ." >&2; fi
exit "$fail"
