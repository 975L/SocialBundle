import { startStimulusApp } from '@symfony/stimulus-bundle';
import ShareButtonsPopupController from './js/share-buttons-popup.js';

// Front-end controllers, used on public pages Loaded as its own <script type="module"> tag (see importmap.php)
const app = startStimulusApp();
app.register('shareButtonsPopup', ShareButtonsPopupController);
