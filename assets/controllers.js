import { startStimulusApp } from '@symfony/stimulus-bundle';
import ShareButtonsPopupController from './js/share-buttons-popup.js';

// Front-end controllers, used on public pages Loaded as its own <script type="module"> tag (see importmap.php). One Stimulus application per page, shared with the other bundles - see UiBundle's controllers.js
globalThis.c975lStimulusApp ??= startStimulusApp();
const app = globalThis.c975lStimulusApp;
app.register('shareButtonsPopup', ShareButtonsPopupController);
