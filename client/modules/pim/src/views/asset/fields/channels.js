/*
 * This file is part of AtroPIM.
 *
 * AtroPIM - Open Source PIM application.
 * Copyright (C) 2020 AtroCore UG (haftungsbeschränkt).
 * Website: https://atropim.com
 *
 * AtroPIM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * AtroPIM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with AtroPIM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "AtroPIM" word.
 */

Espo.define('pim:views/asset/fields/channels', 'treo-core:views/fields/multi-enum',
    Dep => Dep.extend({
        setup() {
            if (this.model.get('entityName') === 'Product') {
                this.ajaxGetRequest(`Product/${this.model.get('entityId')}/channels`, {
                    select: 'name'
                }).then(function (response) {
                    if (response.list) {
                        this.params.options = [];
                        this.params.translatedOptions = [];
                        this.translatedOptions = [];

                        response.list.forEach(function(item) {
                            this.params.options.push(item.id);
                            this.params.translatedOptions[item.id] = item.name;
                            this.translatedOptions = this.params.translatedOptions;

                            this.reRender();
                        }.bind(this));
                    }
                });
            }

            Dep.prototype.setup.call(this);
        }
    })
);