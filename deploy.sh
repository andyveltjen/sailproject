#!/bin/bash
# ─────────────────────────────────────────────────────────────────────────────
# SAIL Project — Deploy script naar Combell
#
# GEBRUIK:
#   Normale deploy (code only):     ./deploy.sh
#   Allereerste keer (+ pagina's):  ./deploy.sh --initial
#   Nieuwe pagina deployen:         ./deploy.sh --page 02.wiki/03.bevindingen/01.mijn-pagina
#   Bestaande pagina updaten:       ./deploy.sh --page 02.wiki/03.bevindingen/01.mijn-pagina --force
#
# ⚠️  user/pages/ wordt NOOIT overschreven bij een gewone deploy.
#     Dit beschermt wiki-inhoud die collega's via het admin dashboard bijhouden.
#
# VEILIGHEIDSREGEL voor --page:
#   Bestaat de pagina al op de server? → geblokkeerd (gebruik --force om toch te overschrijven)
#   Bestaat de pagina nog niet?        → gewoon uploaden
#
# HOE WERKT HET:
#   lftp mirror vergelijkt bestandsgrootte + datum — stuurt alleen gewijzigde
#   bestanden (zelfde principe als rsync). Tweede deploy duurt seconden.
#
# Vereisten: lftp  →  brew install lftp
# ─────────────────────────────────────────────────────────────────────────────

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ENV_FILE="$SCRIPT_DIR/.env.deploy"

if [ ! -f "$ENV_FILE" ]; then
    echo "❌ .env.deploy niet gevonden."
    exit 1
fi

source "$ENV_FILE"

if ! command -v lftp &>/dev/null; then
    echo "❌ lftp niet gevonden. Installeer via: brew install lftp"
    exit 1
fi

LOCAL="$SCRIPT_DIR"

# Incrementele mirror van een specifieke map (enkel gewijzigde bestanden)
sync_dir() {
    local local_dir="$1"
    local remote_dir="$2"
    lftp -c "
        set ftp:ssl-allow no;
        open -u '$FTP_USER','$FTP_PASS' ftp://$FTP_HOST;
        mirror --reverse --only-newer \
            --exclude .DS_Store \
            --exclude .git \
            '$local_dir' '$REMOTE_PATH$remote_dir';
    "
}

# ─────────────────────────────────────────────────────────────────────────────

if [ "$1" == "--initial" ]; then
    echo "▶ INITIËLE deploy — eenmalig, inclusief pagina's en systeem..."
    echo "  ⚠️  Gebruik dit ALLEEN de allereerste keer."
    echo "  ⚠️  Daarna altijd './deploy.sh' (zonder vlag) gebruiken."
    echo ""
    read -p "  Ben je zeker? (typ 'ja' om door te gaan): " confirm
    [ "$confirm" != "ja" ] && echo "Geannuleerd." && exit 0

    lftp -c "
        set ftp:ssl-allow no;
        open -u '$FTP_USER','$FTP_PASS' ftp://$FTP_HOST;
        mirror --reverse --only-newer --verbose \
            --exclude .DS_Store \
            --exclude .git/ \
            --exclude .env \
            --exclude .env.deploy \
            --exclude deploy.sh \
            --exclude translate.php \
            --exclude 'cache/' \
            --exclude 'logs/' \
            --exclude 'tmp/' \
            --exclude 'backup/' \
            --exclude 'user/accounts/' \
            '$LOCAL/' '$REMOTE_PATH';
    "

    echo ""
    echo "✅ Initiële deploy klaar!"
    echo "   Maak nu .env aan op de server met je API-sleutels."

elif [ "$1" == "--page" ]; then
    if [ -z "$2" ]; then
        echo "❌ Geef een paginapad op. Voorbeeld:"
        echo "   ./deploy.sh --page 02.wiki/03.bevindingen/01.scenarioplanning-aanpak"
        exit 1
    fi

    PAGE_PATH="$2"
    FORCE="${3}"
    LOCAL_PAGE="$LOCAL/user/pages/$PAGE_PATH"

    if [ ! -d "$LOCAL_PAGE" ]; then
        echo "❌ Lokale map niet gevonden: $LOCAL_PAGE"
        exit 1
    fi

    # Controleer of de pagina al bestaat op de server
    echo "→ Controleren of pagina al bestaat op de server..."
    REMOTE_EXISTS=$(lftp -c "
        set ftp:ssl-allow no;
        open -u '$FTP_USER','$FTP_PASS' ftp://$FTP_HOST;
        cls '$REMOTE_PATH/user/pages/$PAGE_PATH/' 2>/dev/null && echo 'exists' || echo 'new';
    " 2>/dev/null | tail -1)

    if [ "$REMOTE_EXISTS" = "exists" ] && [ "$FORCE" != "--force" ]; then
        echo ""
        echo "⛔  GEBLOKKEERD — deze pagina bestaat al op de server."
        echo "    Pad: user/pages/$PAGE_PATH"
        echo ""
        echo "    Mogelijk heeft je collega deze pagina online aangemaakt of bewerkt."
        echo "    Upload is geblokkeerd om overschrijven te voorkomen."
        echo ""
        echo "    Wil je toch uploaden? Gebruik dan:"
        echo "    ./deploy.sh --page $PAGE_PATH --force"
        echo ""
        echo "    ⚠️  Let op: --force overschrijft alle bestanden in die map."
        exit 1
    fi

    if [ "$REMOTE_EXISTS" = "exists" ] && [ "$FORCE" = "--force" ]; then
        echo ""
        echo "⚠️  WAARSCHUWING — je overschrijft een bestaande pagina met --force:"
        echo "   📄 user/pages/$PAGE_PATH"
        echo ""
        echo "   Zorg dat je collega's wijzigingen niet verloren gaan!"
        echo ""
        read -p "  Ben je zeker? (typ 'overschrijven' om door te gaan): " confirm
        [ "$confirm" != "overschrijven" ] && echo "Geannuleerd." && exit 0
    else
        echo ""
        echo "▶ NIEUWE PAGINA deploy:"
        echo "  📄 user/pages/$PAGE_PATH"
        echo "  ✅ Alle andere pagina's blijven onaangeraakt"
        echo ""
        read -p "  Doorgaan? (typ 'ja' om te bevestigen): " confirm
        [ "$confirm" != "ja" ] && echo "Geannuleerd." && exit 0
    fi

    sync_dir "$LOCAL_PAGE" "user/pages/$PAGE_PATH"

    echo ""
    echo "✅ Pagina gedeployed: user/pages/$PAGE_PATH"
    echo "   Cache leegmaken: sailproject.ai/admin → Tools → Cache → Clear All"

else
    echo "▶ CODE deploy (incrementeel — enkel gewijzigde bestanden)"
    echo "  ✅ user/pages/ wordt NOOIT aangeraakt"
    echo ""

    echo "→ Thema..."
    sync_dir "$LOCAL/user/themes/sail" "user/themes/sail"

    echo "→ Plugin sail-autotranslate..."
    sync_dir "$LOCAL/user/plugins/sail-autotranslate" "user/plugins/sail-autotranslate"

    echo "→ Plugin sail-search..."
    sync_dir "$LOCAL/user/plugins/sail-search" "user/plugins/sail-search"

    echo "→ Vertalingen..."
    sync_dir "$LOCAL/user/languages" "user/languages"

    echo "→ Blueprints..."
    sync_dir "$LOCAL/user/blueprints" "user/blueprints"

    echo "→ Site config..."
    sync_dir "$LOCAL/user/config" "user/config"

    echo ""
    echo "✅ Deploy klaar! Enkel gewijzigde bestanden zijn gesynchroniseerd."
    echo "   Cache leegmaken: sailproject.ai/admin → Tools → Cache → Clear All"
fi
