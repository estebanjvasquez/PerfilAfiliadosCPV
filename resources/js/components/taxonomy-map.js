/*
 * CPV-TAXONOMY-MAP: filtro por nombre de «Mapa de la taxonomía». Componente Alpine que Filament
 * carga bajo demanda (FilamentAsset / AlpineComponent, x-load), así que funciona también con la
 * navegación SPA del panel. Sin compilación: es un módulo ES escrito a mano.
 *
 * QUÉ CUENTA COMO COINCIDENCIA (revisión `6191485610`, punto B). Un nodo coincide si su nombre
 * contiene el texto (3 letras o más), sin distinguir mayúsculas ni tildes. El resto de lo que se ve
 * es contexto, para no perder la orientación:
 *   - Grupo que coincide: se ven todas sus Familias (y, al abrirlas, todas sus Categorías).
 *   - Familia que coincide: se ve con todas sus Categorías, y con su Grupo como contexto.
 *   - Categoría que coincide: se ve con su Familia y su Grupo (la ruta completa).
 * Solo los nodos que coinciden se resaltan. Con menos de 3 letras el mapa queda completo, sin filtrar.
 *
 * SEGURIDAD. Los nombres se leen del DOM ya escapado por Blade y se vuelven a escribir solo como
 * nodos de texto (createTextNode / textContent). Nunca se usa innerHTML.
 */
const MIN_CHARS = 3

function fold(text) {
    return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
}

// Texto plegado + para cada carácter plegado, el índice del carácter original que lo produjo.
function foldWithMap(text) {
    let folded = ''
    const map = []
    let index = 0
    for (const char of text) {
        const f = fold(char)
        for (let i = 0; i < f.length; i++) {
            folded += f[i]
            map.push(index)
        }
        index += char.length
    }
    map.push(text.length)

    return { folded, map }
}

function node(el, nameEl) {
    const text = nameEl ? nameEl.textContent : ''

    // `map` (plegado -> índice original) se calcula solo si hace falta resaltar este nodo.
    return { el, nameEl, text, folded: fold(text), map: null, highlighted: false }
}

function highlight(item, q) {
    if (!item.nameEl) {
        return
    }
    if (!q) {
        if (item.highlighted) {
            item.nameEl.textContent = item.text
            item.highlighted = false
        }
        return
    }
    if (!item.map) {
        item.map = foldWithMap(item.text).map
    }

    const fragment = document.createDocumentFragment()
    let from = 0
    let at = item.folded.indexOf(q)
    while (at !== -1) {
        const start = item.map[at]
        const end = item.map[at + q.length]
        if (start > from) {
            fragment.append(document.createTextNode(item.text.slice(from, start)))
        }
        const mark = document.createElement('mark')
        mark.textContent = item.text.slice(start, end)
        fragment.append(mark)
        from = end
        at = item.folded.indexOf(q, at + q.length)
    }
    if (from < item.text.length) {
        fragment.append(document.createTextNode(item.text.slice(from)))
    }
    item.nameEl.replaceChildren(fragment)
    item.highlighted = true
}

// Escrituras al DOM solo cuando el valor cambia (medido: con ~2.400 Categorías, reescribir todo en
// cada tecla costaba ~300 ms a 390 px con la CPU 4x más lenta).
function setHidden(el, hidden) {
    if (el.hidden !== hidden) {
        el.hidden = hidden
    }
}

function setOpen(details, open) {
    if (details && details.open !== open) {
        details.open = open
    }
}

function plural(n, one, many) {
    return `${n.toLocaleString('es')} ${n === 1 ? one : many}`
}

export default function cpvTaxonomyMap() {
    return {
        query: '',
        status: '',
        noResults: false,
        ready: false,
        filtered: false,
        groups: [],

        init() {
            this.groups = [...this.$el.querySelectorAll('.cpv-map__group')].map((g) => {
                const group = node(g, g.querySelector(':scope > summary > .cpv-map__n'))
                group.families = [...g.querySelectorAll('.cpv-map__fam')].map((f) => {
                    const family = node(f, f.querySelector('.cpv-map__n'))
                    family.details = f.querySelector('details')
                    family.categories = [...f.querySelectorAll('.cpv-map__cat')].map((c) => node(c, c.querySelector('.cpv-map__n')))

                    return family
                })

                return group
            })
            this.ready = true
        },

        reset() {
            for (const g of this.groups) {
                setHidden(g.el, false)
                setOpen(g.el, false)
                highlight(g, null)
                for (const f of g.families) {
                    setHidden(f.el, false)
                    setOpen(f.details, false)
                    highlight(f, null)
                    for (const c of f.categories) {
                        setHidden(c.el, false)
                        highlight(c, null)
                    }
                }
            }
            this.filtered = false
            this.noResults = false
        },

        apply() {
            const q = fold(this.query.trim())

            if (q.length < MIN_CHARS) {
                if (this.filtered) {
                    this.reset()
                }
                this.status = q.length > 0 ? `Escriba al menos ${MIN_CHARS} letras para filtrar.` : ''
                return
            }

            let groupHits = 0
            let familyHits = 0
            let categoryHits = 0

            for (const g of this.groups) {
                // 1) Coincidencias (solo JavaScript, sin tocar el DOM).
                const groupMatch = g.folded.includes(q)
                let anyFamilyVisible = false
                const families = g.families.map((f) => {
                    const familyMatch = f.folded.includes(q)
                    const categoryMatches = f.categories.map((c) => c.folded.includes(q))
                    const anyCategoryMatch = categoryMatches.includes(true)
                    const visible = familyMatch || groupMatch || anyCategoryMatch
                    familyHits += familyMatch ? 1 : 0
                    categoryHits += categoryMatches.filter(Boolean).length
                    anyFamilyVisible = anyFamilyVisible || visible

                    return { f, familyMatch, categoryMatches, anyCategoryMatch, visible }
                })
                const groupVisible = groupMatch || anyFamilyVisible
                groupHits += groupMatch ? 1 : 0

                // 2) DOM. Lo que queda dentro de un nodo oculto no se toca: no se ve, y se recalcula
                // completo la próxima vez que su padre vuelva a verse.
                setHidden(g.el, !groupVisible)
                setOpen(g.el, groupVisible)
                highlight(g, groupMatch ? q : null)
                if (!groupVisible) {
                    continue
                }

                for (const { f, familyMatch, categoryMatches, anyCategoryMatch, visible } of families) {
                    setHidden(f.el, !visible)
                    highlight(f, familyMatch ? q : null)
                    if (!visible) {
                        continue
                    }
                    setOpen(f.details, familyMatch || anyCategoryMatch)
                    // Solo se ocultan Categorías en una Familia abierta por coincidir alguna de
                    // ellas; si coincide la Familia o el Grupo, se ven todas.
                    const onlyMatches = anyCategoryMatch && !familyMatch && !groupMatch
                    f.categories.forEach((c, i) => {
                        setHidden(c.el, onlyMatches && !categoryMatches[i])
                        highlight(c, categoryMatches[i] ? q : null)
                    })
                }
            }

            const total = groupHits + familyHits + categoryHits
            this.filtered = true
            this.noResults = total === 0
            this.status = total === 0
                ? 'Ninguna coincidencia.'
                : `${plural(total, 'coincidencia', 'coincidencias')}: ${plural(groupHits, 'Grupo', 'Grupos')}, ${plural(familyHits, 'Familia', 'Familias')} y ${plural(categoryHits, 'Categoría', 'Categorías')}.`
        },

        clear() {
            this.query = ''
            this.apply()
            this.$refs.input.focus()
        },
    }
}
