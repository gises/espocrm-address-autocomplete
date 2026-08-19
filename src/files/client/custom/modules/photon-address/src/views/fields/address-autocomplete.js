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
     * @property {?string} houseNumber  Roh-Hausnummer des Treffers.
     * @property {boolean} numberFirst  Hausnummer-vor-Strasse-Konvention.
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

        /**
         * Zuletzt getippter Suchbegriff. devbridge ueberschreibt das
         * Eingabefeld VOR dem onSelect-Callback - zum Zeitpunkt der
         * Uebernahme steht der getippte Text also nicht mehr im DOM.
         * @type {?string}
         */
        photonLastTerm = null

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

            this.photonLastTerm = term;

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
         * devbridge-autocomplete setzt die Container-Breite zweimal pro
         * Anzeige fest auf die Breite des Strassenfelds: in
         * adjustContainerWidth() VOR beforeRender und in fixPosition()
         * DANACH (sowie bei jedem window-resize). Ein Inline-Stil aus
         * beforeRender wird dadurch sofort wieder ueberschrieben - nur
         * eine !important-Regel aus einem Stylesheet gewinnt gegen die
         * per .css() gesetzte Inline-Breite. Die Feldbreite bleibt als
         * min-width erhalten (die ruehrt devbridge nicht an), nach oben
         * waechst der Container mit dem Inhalt bis knapp an den
         * Viewport-Rand.
         *
         * @param {HTMLElement} container
         */
        adjustSuggestionContainer(container) {
            this.ensureSuggestionStyle();

            container.classList.add('photon-address-suggestions');

            if (container.style.width && container.style.width !== 'auto') {
                container.style.minWidth = container.style.width;
            }
        }

        /**
         * Legt die Stylesheet-Regel einmalig pro Dokument an.
         */
        ensureSuggestionStyle() {
            const id = 'photon-address-suggestions-style';

            if (document.getElementById(id)) {
                return;
            }

            const style = document.createElement('style');

            style.id = id;
            style.textContent =
                '.autocomplete-suggestions.photon-address-suggestions {' +
                ' width: auto !important;' +
                ' max-width: calc(100vw - 30px);' +
                '}';

            document.head.appendChild(style);
        }

        /**
         * Bereits ausgefuellte Subfelder (Ort, PLZ, Land) gewichten die
         * Strassensuche serverseitig. Die Werte kommen aus dem DOM,
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
            const street = this.buildStreetValue(item);

            const map = {
                [this.streetField]: street,
                [this.postalCodeField]: item.zip,
                [this.cityField]: item.city,
                [this.stateField]: item.state,
                [this.countryField]: item.country,
            };

            // Sperrt den unmittelbar folgenden Fokus-Lookup.
            this.photonAcceptedValue = (street || '').trim();

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
         * OSM kennt laengst nicht jede Hausnummer (in UK besonders
         * lueckenhaft, weil Royal Mails Adressdatenbank proprietaer ist
         * und nicht importiert werden darf). Waehlt der Nutzer einen
         * Strassen-Treffer ohne Hausnummer, bleibt eine bereits getippte
         * Nummer deshalb erhalten statt verworfen zu werden -
         * positioniert nach der Konvention des Treffer-Landes.
         *
         * @param {PhotonSuggestion} item
         * @return {string}
         */
        buildStreetValue(item) {
            const street = item.street || '';

            if (street === '' || item.houseNumber) {
                return street;
            }

            const typedNumber = this.extractHouseNumber(this.photonLastTerm || '');

            if (!typedNumber) {
                return street;
            }

            // Steht das Token bereits im Strassennamen ("Route 66"),
            // nichts doppelt einfuegen.
            const contained = street
                .split(/\s+/)
                .some(token => token.toLowerCase() === typedNumber.toLowerCase());

            if (contained) {
                return street;
            }

            return item.numberFirst ? `${typedNumber} ${street}` : `${street} ${typedNumber}`;
        }

        /**
         * Erstes oder letztes Token des Suchbegriffs, das wie eine
         * Hausnummer aussieht ("79", "12a", "76-3", "29/2").
         *
         * @param {string} term
         * @return {?string}
         */
        extractHouseNumber(term) {
            const tokens = term.trim().split(/\s+/);

            // Nur eine Nummer ohne Strassenname ist keine Adresse.
            if (tokens.length < 2) {
                return null;
            }

            const pattern = /^\d{1,5}[a-z]?(?:[/\-]\d{1,4}[a-z]?)?$/i;

            if (pattern.test(tokens[0])) {
                return tokens[0];
            }

            const last = tokens[tokens.length - 1];

            return pattern.test(last) ? last : null;
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
