import './bootstrap';
import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.min.css';
import Chart from 'chart.js/auto';
import Alpine from 'alpinejs';
import Swal from 'sweetalert2';
import {
    Activity,
    Bell,
    Bot,
    Building2,
    CalendarCheck,
    Check,
    ChevronDown,
    CircleDollarSign,
    ClipboardList,
    CreditCard,
    Droplets,
    Eye,
    EyeOff,
    FileBarChart2,
    FileText,
    HandCoins,
    IdCard,
    LayoutDashboard,
    Loader2,
    LogIn,
    LockKeyhole,
    LogOut,
    Menu,
    Moon,
    Package,
    Plus,
    Printer,
    QrCode,
    ReceiptText,
    Search,
    Settings,
    ShieldCheck,
    Shirt,
    Sparkles,
    Scale,
    Store,
    Sun,
    Tags,
    TriangleAlert,
    Trash2,
    UserRound,
    Users,
    Wallet,
    WashingMachine,
    X,
} from 'lucide-static';

window.Alpine = Alpine;
window.Swal = Swal;
window.flatpickr = flatpickr;
window.Chart = Chart;
window.toast = Swal.mixin({
    toast: true,
    position: 'bottom-end',
    showConfirmButton: false,
    timer: 5200,
    timerProgressBar: true,
    showClass: {
        popup: 'swal2-show',
        backdrop: 'swal2-noanimation',
    },
    hideClass: {
        popup: 'swal2-hide',
        backdrop: 'swal2-noanimation',
    },
});

const icons = {
    activity: Activity,
    bell: Bell,
    bot: Bot,
    building: Building2,
    calendar: CalendarCheck,
    check: Check,
    chevronDown: ChevronDown,
    cycles: Activity,
    dashboard: LayoutDashboard,
    dollar: CircleDollarSign,
    expense: CircleDollarSign,
    eye: Eye,
    eyeOff: EyeOff,
    inventory: Package,
    fileText: FileText,
    'file-text': FileText,
    jobOrders: ClipboardList,
    laundry: WashingMachine,
    loader: Loader2,
    lock: LockKeyhole,
    login: LogIn,
    logout: LogOut,
    menu: Menu,
    moon: Moon,
    payments: CreditCard,
    plus: Plus,
    printer: Printer,
    qr: QrCode,
    receipt: ReceiptText,
    receivables: HandCoins,
    reports: FileBarChart2,
    search: Search,
    services: Tags,
    settings: Settings,
    shieldCheck: ShieldCheck,
    shirt: Shirt,
    sms: Bell,
    sparkles: Sparkles,
    scale: Scale,
    store: Store,
    sun: Sun,
    alertTriangle: TriangleAlert,
    trash: Trash2,
    user: UserRound,
    users: Users,
    wallet: Wallet,
    x: X,
    branches: Building2,
    customers: Users,
    attendance: CalendarCheck,
    employees: IdCard,
    smsLogs: Bell,
};

window.renderLucideIcons = () => {
    document.querySelectorAll('[data-lucide]').forEach((node) => {
        const name = node.dataset.lucide;
        const svg = icons[name];

        if (!svg) {
            return;
        }

        const wrapper = document.createElement('span');
        wrapper.innerHTML = svg.trim();
        const icon = wrapper.firstElementChild;

        Array.from(node.attributes).forEach((attribute) => {
            if (attribute.name !== 'data-lucide') {
                icon.setAttribute(attribute.name, attribute.value);
            }
        });

        icon.setAttribute('class', node.getAttribute('class') || 'h-5 w-5');
        icon.setAttribute('aria-hidden', 'true');
        node.replaceWith(icon);
    });
};

const appThemeDefault = Boolean(window.appDarkModeDefault);
const appThemeDefaultKey = String(appThemeDefault);
const storedThemeDefaultKey = localStorage.getItem('themeDefault');
const storedTheme = localStorage.getItem('theme');

Alpine.store('theme', {
    dark: storedTheme && storedThemeDefaultKey === appThemeDefaultKey
        ? storedTheme === 'dark'
        : appThemeDefault,

    init() {
        this.apply();
    },

    toggle() {
        this.dark = !this.dark;
        localStorage.setItem('theme', this.dark ? 'dark' : 'light');
        localStorage.setItem('themeDefault', appThemeDefaultKey);
        this.apply();
    },

    apply() {
        localStorage.setItem('themeDefault', appThemeDefaultKey);
        document.documentElement.classList.toggle('dark', this.dark);
        document.documentElement.style.colorScheme = this.dark ? 'dark' : 'light';
    }
});

Alpine.start();

document.addEventListener('DOMContentLoaded', window.renderLucideIcons);
document.addEventListener('alpine:init', () => {
    queueMicrotask(window.renderLucideIcons);
});
