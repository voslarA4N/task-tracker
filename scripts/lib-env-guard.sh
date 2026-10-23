# shellcheck shell=sh
# Ochrana destruktivnych skriptov (make reset) pred behom mimo vyvoja (CR-02).
# Allow-list: povolene je len development|testing. Vsetko ostatne (vratane
# chybajucej hodnoty, chybajuceho .env alebo neznameho formatu) sa odmietne.
# Testy: tests/unit/Scripts/DbResetGuardTest.php

# Normalizuje hodnotu z dotenv: odstrani inline komentar, uvodzovky (" aj '),
# medzery, tabulatory a CR (CRLF z Windows editora na /mnt/c).
env_guard_normalize() {
  printf '%s' "$1" | sed 's/[[:space:]]#.*$//' | tr -d " \"'\r\t"
}

# Vypise CI_ENVIRONMENT z .env tak, ako ho cita CI4 DotEnv: prvy vyskyt,
# aj s prefixom "export". Zakomentovane riadky sa ignoruju.
ci_env_file_value() {
  envfile=$1
  [ -f "$envfile" ] || return 0
  line=$(grep -E '^[[:space:]]*(export[[:space:]]+)?CI_ENVIRONMENT[[:space:]]*=' "$envfile" 2>/dev/null | head -n1 || true)
  [ -n "$line" ] || return 0
  env_guard_normalize "${line#*=}"
}

# Uspeje len pre development|testing. Pouzitie: require_dev_env_value hodnota [zdroj]
require_dev_env_value() {
  case "$1" in
    development|testing) return 0 ;;
  esac
  echo "REFUSED: CI_ENVIRONMENT='$1'${2:+ ($2)}; povolene je len development alebo testing" >&2
  return 1
}

# Overi procesne prostredie (ak je CI_ENVIRONMENT nastavene, CI4 mu da prednost)
# aj .env (musi existovat a byt povolene). Pouzitie: require_dev_env [.env]
require_dev_env() {
  envfile=${1:-.env}
  if [ -n "${CI_ENVIRONMENT+x}" ]; then
    require_dev_env_value "$(env_guard_normalize "$CI_ENVIRONMENT")" "procesne prostredie" || return 1
  fi
  require_dev_env_value "$(ci_env_file_value "$envfile")" "$envfile"
}
