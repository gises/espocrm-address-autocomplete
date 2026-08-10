/**
 * Admin-Seite der Extension: Administration > Photon Address Autocomplete.
 *
 * Nutzt den generischen Mechanismus des Admin-Controllers: der
 * adminPanel-Eintrag traegt ein recordView, actionPage baut daraus eine
 * Settings-Edit-Seite (Route Admin/:page -> actionPage). Das Layout ist
 * bewusst inline definiert - fuer drei Felder lohnt keine Layout-Datei.
 */

define(['views/settings/record/edit'], function (Dep) {

    return class extends Dep {

        saveAndContinueEditingAction = false

        detailLayout = [
            {
                rows: [
                    [
                        {name: 'photonAddressCountryCodes'},
                        false,
                    ],
                    [
                        {name: 'photonAddressUrl'},
                        {name: 'photonAddressLang'},
                    ],
                ],
            },
        ]
    };
});
