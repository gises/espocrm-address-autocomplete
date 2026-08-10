/**
 * Photon Address Autocomplete (DACH) – EspoCRM 10.x
 *
 * Erweitert das Standard-Address-Feld um ein Autocomplete auf dem
 * Strassenfeld. Die Vorschlaege kommen vom hauseigenen Proxy-Endpoint
 * /api/v1/PhotonAddress/search (Same-Origin, authentifiziert).
 *
 * Nutzt die Autocomplete-Komponente des Cores (ui/autocomplete), damit
 * Dropdown-Optik, Tastaturnavigation und Positionierung identisch zu den
 * Land-/Ort-/Kanton-Feldern des Address-Felds sind.
 *
 * Modulformat: AMD. Der Espo-Loader laedt Dateien aus
 * client/custom/modules/<mod>/src/ als AMD-Skript. ESM waere nur mit
 * "jsTranspiled": true plus vorgelagertem Transpile-Build moeglich -
 * dafuer lohnt sich der Build-Schritt bei einer einzelnen Datei nicht.
 *
 * Registrierung ueber entityDefs:
 *   "billingAddress": { "view": "photon-address:views/fields/address-autocomplete" }
 */

define(['views/fields/address', 'ui/autocomplete', 'ajax'], function (AddressFieldView, Autocomplete, Ajax) {

    const MIN_CHARS = 3;

    /**
     * @typedef {Object} PhotonSuggestion
     * @property {string} value    Wert fuer das Strassenfeld.
     * @property {string} label    Anzeigetext im Dropdown.
     * @property {?string} street
     * @property {?string} zip
     * @property {?string} city
     * @property {?string} state
     * @property {?string} country
     */

    return class extends AddressFieldView {

        photonMinChars = MIN_CHARS

        /**
         * Zuletzt uebernommener Strassenwert. Siehe photonLookup().
         * @type {?string}
         */
        photonAcceptedValue = null

        afterRender() {
            super.afterRender();

            if (!this.isEditMode()) {
                return;
            }

            // Das Strassenfeld ist eine Textarea mit data-name="<name>Street".
            const element = this.$el.find(`[data-name="${this.streetField}"]`).get(0);

            if (!element) {
                return;
            }

            const autocomplete = new Autocomplete(element, {
                name: `${this.name}PhotonStreet`,
                minChars: this.photonMinChars,
                focusOnSelect: true,
                autoSelectFirst: false,
                triggerSelectOnValidInput: false,
                forceHide: true,
                lookupFunction: query => this.photonLookup(query),
                formatResult: item => this.formatSuggestion(item),
                onSelect: item => this.applySuggestion(item),
                beforeRender: container => this.adjustSuggestionContainer(container),
            });

            // Gleiches Aufraeum-Muster wie im Core-Address-Feld.
            this.once('render remove', () => autocomplete.dispose());
        }

        /**
         * @param {string} query
         * @return {Promise<PhotonSuggestion[]>}
         */
        photonLookup(query) {
            const term = (query || '').trim();

            // Anforderung 1: unter 3 Zeichen gar nicht erst anfragen.
            // minChars deckt das ab; die Pruefung faengt reine
            // Whitespace-Eingaben zusaetzlich ab.
            if (term.length < this.photonMinChars) {
                return Promise.resolve([]);
            }

            // Nach einer Uebernahme ruft das Plugin bei jedem Fokus erneut
            // onValueChange() auf (devbridge-autocomplete, onFocus: bei
            // val().length >= minChars). Der uebernommene Strassenwert
            // liefert dieselben Treffer - die Liste ginge unmittelbar nach
            // dem Klick wieder auf. Eine leere Trefferliste laesst
            // suggest() stattdessen hide() aufrufen.
            // Entspricht dem Verhalten der Core-Felder (Land/Ort/Kanton),
            // deren lookupFilter bei exakter Uebereinstimmung ebenfalls
            // nichts zurueckgibt.
            if (this.photonAcceptedValue !== null && term === this.photonAcceptedValue) {
                return Promise.resolve([]);
            }

            return Ajax.getRequest('PhotonAddress/search', this.buildSearchParams(term))
                .then(response => {
                    if (!Array.isArray(response)) {
                        return [];
                    }

                    return response.map(item => Object.assign({}, item, {
                        // Wert, den das Strassenfeld nach der Auswahl traegt.
                        value: item.street || '',
                    }));
                })
                .catch(xhr => {
                    // Ein ausgefallener Geocoder darf weder das Formular
                    // blockieren noch waehrend des Tippens einen
                    // Fehlerdialog aufpoppen lassen.
                    if (xhr && typeof xhr === 'object' && 'errorIsHandled' in xhr) {
                        xhr.errorIsHandled = true;
                    }

                    return [];
                });
        }

        /**
         * devbridge-autocomplete setzt die Container-Breite bei jedem
         * suggest() fix auf die Breite des Strassenfelds; die Labels
         * (Strasse - PLZ Ort (Kanton), Land) sind dafuer regelmaessig zu
         * lang und werden vom Theme abgeschnitten (white-space: nowrap,
         * overflow: hidden). Die Feldbreite bleibt als Untergrenze, nach
         * oben darf der Container mit dem Inhalt wachsen - beforeRender
         * laeuft nach adjustContainerWidth() und gewinnt daher.
         *
         * @param {HTMLElement} container
         */
        adjustSuggestionContainer(container) {
            if (container.style.width && container.style.width !== 'auto') {
                container.style.minWidth = container.style.width;
            }

            container.style.width = 'auto';
            container.style.maxWidth = 'calc(100vw - 30px)';
        }

        /**
         * Bereits ausgefuellte Subfelder (Ort, PLZ, Land) engen die
         * Strassensuche serverseitig ein. Die Werte kommen aus dem DOM,
         * nicht aus dem Model - gleiche Begruendung wie in applySuggestion().
         *
         * @param {string} term
         * @return {Object<string, string>}
         */
        buildSearchParams(term) {
            const params = {q: term};

            const contextFields = {
                city: this.cityField,
                zip: this.postalCodeField,
                country: this.countryField,
            };

            Object.keys(contextFields).forEach(key => {
                const value = (this.$el.find(`[data-name="${contextFields[key]}"]`).val() || '').trim();

                if (value !== '') {
                    params[key] = value;
                }
            });

            return params;
        }

        /**
         * @param {PhotonSuggestion} item
         * @return {string}
         */
        formatSuggestion(item) {
            return this.escapeHtml(item.label || item.value || '');
        }

        /**
         * Das Address-Feld liest seine Werte in fetch() direkt aus dem DOM,
         * nicht aus dem Model. Deshalb muessen die Inputs beschrieben werden -
         * ein reines model.set() wuerde beim Speichern wieder ueberschrieben.
         *
         * @param {PhotonSuggestion} item
         */
        applySuggestion(item) {
            const map = {
                [this.streetField]: item.street,
                [this.postalCodeField]: item.zip,
                [this.cityField]: item.city,
                [this.stateField]: item.state,
                [this.countryField]: item.country,
            };

            // Sperrt den unmittelbar folgenden Fokus-Lookup.
            this.photonAcceptedValue = (item.street || '').trim();

            const attributes = {};

            Object.keys(map).forEach(attribute => {
                const value = map[attribute] || '';

                this.$el.find(`[data-name="${attribute}"]`).val(value);

                attributes[attribute] = value !== '' ? value : null;
            });

            this.model.set(attributes, {ui: true});

            // Hoehe der Strassen-Textarea nachziehen (Core-Verhalten).
            if (typeof this.controlStreetTextareaHeight === 'function') {
                this.controlStreetTextareaHeight();
            }

            // Programmatisches .val() loest kein change-Event aus.
            this.trigger('change');
        }

        /**
         * @param {string} value
         * @return {string}
         */
        escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }
    };
});
