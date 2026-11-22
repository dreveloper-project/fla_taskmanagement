import { installArchiveDownloads } from './archive-download.js';
import { installDateTimeInputs } from './date-time-input.js';
import { installFileUploadValidation } from './file-upload-validation.js';
import { installFormLoading } from './form-loading.js';
import { installFormNotifications } from './form-notifications.js';
import { installPageLoading } from './page-loading.js';
import { installTheme } from './theme.js';

installArchiveDownloads(document);
installDateTimeInputs(document);
installFileUploadValidation(document);
installFormLoading(document);
installFormNotifications(document);
installPageLoading(document, window);
installTheme(document, window);
