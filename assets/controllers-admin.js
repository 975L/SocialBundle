import { startStimulusApp } from '@symfony/stimulus-bundle';
import './js/share-buttons-preview.js';
import './js/share-buttons-networks-sort.js';
import './js/social-link-network-toggle.js';
import './js/social-links-preview.js';

// Back-office scripts, used only in EasyAdmin. Front-end controllers live in controllers.js
// Loaded as its own <script type="module"> tag (see importmap.php), starts its own Stimulus app
startStimulusApp();
