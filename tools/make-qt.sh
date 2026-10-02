#!/bin/bash
# Builds the OVT 생명의 삶 calendar (qt-2026.json) from the monthly "본문 알려 드립니다"
# notices on the Duranno 생명의삶 board.
#
#   tools/make-qt.sh --list          show the 본문 notices currently on the board
#   tools/make-qt.sh <sn> [<sn>...]  add those notices' months to the calendar file
#                                    (sn = number in notice_detail.asp?sn=NNNN)
#
# A notice reads "*2611 본문 1 몬 1:1~14 2 몬 1:15~25 ..." — day number, book
# abbreviation (the "short" names in bible.json), passage. Anything that does
# not fit that pattern aborts without touching the file, so a changed notice
# layout is noticed instead of silently producing a wrong calendar.
# Dates already in the file are replaced (their title, if any, is kept).
set -euo pipefail
cd "$(dirname "$0")/.."

BIBLE=bible.json
OUT=${OUT:-qt-2026.json}
BASE="https://www.duranno.com/qt/view"

fetch() { curl -sS -L -m 30 -A "Mozilla/5.0" "$1" | iconv -f euc-kr -t utf-8; }

if [ "${1:-}" = "--list" ]; then
  fetch "$BASE/notice.asp" |
    sed -nE "s/.*notice_detail\.asp\?sn=([0-9]+)[^>]*><span[^>]*>([^<]*본문[^<]*)<\/span>.*/\1  \2/p"
  exit 0
fi
[ $# -ge 1 ] || { sed -n '2,13p' "$0"; exit 1; }

TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT

# abbreviation -> full book name
awk '/"name":/{gsub(/.*"name": "|",?$/,""); n=$0} /"short":/{s=$0; gsub(/.*"short": "|",?$/,"",s); print s "\t" n}' "$BIBLE" > "$TMP/books.tsv"

# existing calendar -> date<TAB>book<TAB>range<TAB>title
if [ -f "$OUT" ]; then
  awk '
    /^ "[0-9-]+": \{/ { d=$1; gsub(/[":]/,"",d); b=r=t="" }
    /^  "book":/  { b=$0; sub(/^  "book": "/,"",b); sub(/",?$/,"",b) }
    /^  "range":/ { r=$0; sub(/^  "range": "/,"",r); sub(/",?$/,"",r) }
    /^  "title":/ { t=$0; sub(/^  "title": "/,"",t); sub(/",?$/,"",t) }
    /^ \},?$/     { print d "\t" b "\t" r "\t" t }
  ' "$OUT" > "$TMP/old.tsv"
else
  : > "$TMP/old.tsv"
fi

: > "$TMP/new.tsv"
for sn in "$@"; do
  fetch "$BASE/notice_detail.asp?sn=$sn&page=notice&pg=1" |
    sed -e 's/<[^>]*>/ /g' -e 's/&nbsp;/ /g' | tr -s ' \t\r' ' ' > "$TMP/page.txt"
  code=$(grep -oE '\*[0-9]{4} 본문' "$TMP/page.txt" | head -1 | grep -oE '[0-9]{4}' || true)
  [ -n "$code" ] || { echo "sn=$sn: '*YYMM 본문' 표시를 찾지 못했습니다 (공지 형식이 다를 수 있어요)" >&2; exit 2; }
  yy=${code:0:2}; mm=${code:2:2}

  body=$(grep -oE "\*$code 본문 .*" "$TMP/page.txt" | head -1 | sed -E "s/^\*$code 본문 //; s/ +$//")
  items=$(printf '%s\n' "$body" | grep -oE '[0-9]+ [^ 0-9]+ ([0-9]+:[0-9]+(~([0-9]+:)?[0-9]+)?|[0-9]+(~[0-9]+)?장)' || true)
  rebuilt=$(printf '%s\n' "$items" | tr '\n' ' ' | sed -E 's/ +$//')
  # the match must account for the whole list, with nothing left over
  if [ "$rebuilt" != "$(printf '%s' "$body" | sed -E 's/ +$//')" ]; then
    echo "sn=$sn (20$yy-$mm): 본문 목록의 형식을 이해하지 못했습니다." >&2
    echo "  읽은 내용: $body" >&2
    exit 2
  fi

  days=0; prev=0
  while read -r day abbr range; do
    name=$(awk -F'\t' -v a="$abbr" '$1==a{print $2; exit}' "$TMP/books.tsv")
    [ -n "$name" ] || { echo "sn=$sn: 알 수 없는 책 약칭 '$abbr' (날짜 $day)" >&2; exit 2; }
    [ "$day" -eq $((prev + 1)) ] || { echo "sn=$sn: 날짜 번호가 이어지지 않습니다 ($prev 다음 $day)" >&2; exit 2; }
    prev=$day; days=$((days + 1))
    printf '20%s-%s-%02d\t%s\t%s\t\n' "$yy" "$mm" "$day" "$name" "$range" >> "$TMP/new.tsv"
  done <<< "$items"
  last=$(date -d "20$yy-$mm-01 +1 month -1 day" +%d 2>/dev/null || echo "$days")
  [ "$days" -eq "$((10#$last))" ] || { echo "sn=$sn: 20$yy-$mm 은 ${last}일까지인데 본문은 ${days}일치입니다." >&2; exit 2; }
  echo "sn=$sn: 20$yy-$mm ${days}일 읽음" >&2
done

# merge (new wins, old title kept), sort by date, write in the file's own layout
awk -F'\t' '
  NR==FNR { book[$1]=$2; range[$1]=$3; title[$1]=$4; seen[$1]=1; next }
  { if(!($1 in seen)) title[$1]=""; book[$1]=$2; range[$1]=$3; seen[$1]=1 }
  END {
    n=0; for(d in seen) keys[++n]=d
    for(i=2;i<=n;i++){ v=keys[i]; j=i-1; while(j>0 && keys[j]>v){ keys[j+1]=keys[j]; j-- } keys[j+1]=v }
    print "{"
    for(i=1;i<=n;i++){
      d=keys[i]
      printf " \"%s\": {\n  \"book\": \"%s\",\n  \"range\": \"%s\",\n  \"ref\": \"%s %s\"", d, book[d], range[d], book[d], range[d]
      if(title[d]!="") printf ",\n  \"title\": \"%s\"", title[d]
      printf "\n }%s\n", (i<n ? "," : "")
    }
    print "}"
  }
' "$TMP/old.tsv" "$TMP/new.tsv" > "$TMP/out.json"

mv "$TMP/out.json" "$OUT"
echo "$OUT: $(grep -c '"book"' "$OUT")일치 (마지막 날짜 $(grep -oE '"20[0-9-]+"' "$OUT" | tail -1 | tr -d '"'))" >&2
