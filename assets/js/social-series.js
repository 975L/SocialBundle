/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

import { Controller } from "@hotwired/stimulus";

// The series form (see management/social_series.html.twig): a field only one pace or one mode reads - "field:value,value" in data-social-series-when - shown with it alone, the others hidden but still sent
export default class extends Controller {
    connect() {
        this.toggle();
    }

    toggle() {
        const data = new FormData(this.element);
        for (const element of this.element.querySelectorAll("[data-social-series-when]")) {
            const [field, values] = element.dataset.socialSeriesWhen.split(":");
            element.hidden = !values.split(",").includes(String(data.get(`${this.element.name}[${field}]`)));
        }
    }
}
