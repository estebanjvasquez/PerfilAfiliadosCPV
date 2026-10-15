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
    const { folded, map } = foldWithMap(text)

    return { el, nameEl, text, folded, map, highlighted: false }
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
            const name = (el) => el.querySelector('[data-map-name]')
            this.groups = [...this.$el.querySelectorAll('[data-map-node="group"]')].map((g) => {
                const group = node(g, g.querySelector(':scope > summary [data-map-name]'))
                group.families = [...g.querySelectorAll('[data-map-node="family"]')].map((f) => {
                    const family = node(f, name(f))
                    family.details = f.querySelector('details')
                    family.categories = [...f.querySelectorAll('[data-map-node="category"]')].map((c) => node(c, name(c)))

                    return family
                })

                return group
            })
            this.ready = true
        },

        reset() {
            for (const g of this.groups) {
                g.el.hidden = false
                g.el.open = false
                highlight(g, null)
                for (const f of g.families) {
                    f.el.hidden = false
                    if (f.details) {
                        f.details.open = false
                    }
                    highlight(f, null)
                    for (const c of f.categories) {
                        c.el.hidden = false
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
                const groupMatch = g.folded.includes(q)
                let anyFamilyVisible = false

                for (const f of g.families) {
                    const familyMatch = f.folded.includes(q)
                    let anyCategoryMatch = false

                    for (const c of f.categories) {
                        const categoryMatch = c.folded.includes(q)
                        c.el.hidden = !(categoryMatch || familyMatch || groupMatch)
                        highlight(c, categoryMatch ? q : null)
                        if (categoryMatch) {
                            categoryHits++
                            anyCategoryMatch = true
                        }
                    }

                    const familyVisible = familyMatch || groupMatch || anyCategoryMatch
                    f.el.hidden = !familyVisible
                    if (f.details) {
                        f.details.open = familyMatch || anyCategoryMatch
                    }
                    highlight(f, familyMatch ? q : null)
                    if (familyMatch) {
                        familyHits++
                    }
                    anyFamilyVisible = anyFamilyVisible || familyVisible
                }

                const groupVisible = groupMatch || anyFamilyVisible
                g.el.hidden = !groupVisible
                g.el.open = groupVisible
                highlight(g, groupMatch ? q : null)
                if (groupMatch) {
                    groupHits++
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
