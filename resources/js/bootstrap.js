import axios from 'axios';
import { installSessionGuard } from './lib/apiError';

window.axios = axios;
installSessionGuard(window.axios);

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
