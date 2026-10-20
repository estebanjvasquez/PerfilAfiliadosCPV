#!/bin/sh
# Per-page header check of PDFs in the current directory (poppler): text of each page and image placements.
for f in *.pdf; do
  n=$(pdfinfo "$f" | awk '/^Pages:/{print $2}')
  echo "== $f pages=$n"
  p=1
  while [ "$p" -le "$n" ]; do
    t=$(pdftotext -f $p -l $p -enc UTF-8 "$f" - | tr '\n' ' ')
    title=$(echo "$t" | grep -o 'PERFIL COMPLETO DE LA EMPRESA\|PERFIL DE LA EMPRESA — SECCIONES SELECCIONADAS' | head -1)
    rif=$(echo "$t" | grep -o '· RIF J[0-9]*' | head -1)
    imgs=$(pdfimages -f $p -l $p -list "$f" | tail -n +3 | awk '$3=="image"' | wc -l)
    foot=$(echo "$t" | grep -o 'Página [0-9]* de [0-9]*' | head -1)
    echo "p$p | $title | $rif | images=$imgs | $foot"
    p=$((p+1))
  done
done
