import { startStimulusApp } from '@symfony/stimulus-bundle';
import './js/share-buttons-preview.js';
import './js/share-buttons-networks-sort.js';
import './js/social-link-network-toggle.js';
import './js/social-links-preview.js';
import SocialCalendarController from './js/social-calendar.js';
import SocialSeriesController from './js/social-series.js';

// Back-office scripts, used only in EasyAdmin. Front-end controllers live in controllers.js Loaded as its own <script type="module"> tag (see importmap.php). One Stimulus application per page, shared with the other bundles - see UiBundle's controllers.js
globalThis.c975lStimulusApp ??= startStimulusApp();
const app = globalThis.c975lStimulusApp;

// Kebab-case on purpose: the identifier is what Stimulus derives data-social-calendar-* from
app.register('social-calendar', SocialCalendarController);
app.register('social-series', SocialSeriesController);
