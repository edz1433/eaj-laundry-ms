import './bootstrap';
import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.min.css';
import Chart from 'chart.js/auto';
import Alpine from 'alpinejs';
import Swal from 'sweetalert2';
import {
    Activity,
    Bell,
    BriefcaseBusiness,
    Building2,
    CalendarCheck,
    ChevronDown,
    CircleDollarSign,
    ClipboardList,
    CreditCard,
    Droplets,
    Eye,
    EyeOff,
    FileBarChart2,
    FolderCog,
    HandCoins,
    LayoutDashboard,
    Loader2,
    LogIn,
    LockKeyhole,
    LogOut,
    Menu,
    Moon,
    Package,
    Plus,
    ReceiptText,
    Search,
    Settings,
    ShieldCheck,
    Sparkles,
    Store,
    Sun,
    Tags,
    Trash2,
    UserRound,
    Users,
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
    briefcase: BriefcaseBusiness,
    building: Building2,
    calendar: CalendarCheck,
    chevronDown: ChevronDown,
    cycles: Activity,
    dashboard: LayoutDashboard,
    developerSettings: ShieldCheck,
    dollar: CircleDollarSign,
    eye: Eye,
    eyeOff: EyeOff,
    inventory: Package,
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
    receipt: ReceiptText,
    receivables: HandCoins,
    reports: FileBarChart2,
    search: Search,
    services: Tags,
    settings: Settings,
    shieldCheck: ShieldCheck,
    sms: Bell,
    sparkles: Sparkles,
    store: Store,
    sun: Sun,
    trash: Trash2,
    user: UserRound,
    users: Users,
    x: X,
    branches: Building2,
    customers: Users,
    attendance: CalendarCheck,
    payroll: BriefcaseBusiness,
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

Alpine.store('theme', {
    dark: localStorage.getItem('theme')
        ? localStorage.getItem('theme') === 'dark'
        : Boolean(window.appDarkModeDefault),

    init() {
        this.apply();
    },

    toggle() {
        this.dark = !this.dark;
        localStorage.setItem('theme', this.dark ? 'dark' : 'light');
        this.apply();
    },

    apply() {
        document.documentElement.classList.toggle('dark', this.dark);
        document.documentElement.style.colorScheme = this.dark ? 'dark' : 'light';
    }
});

Alpine.start();

document.addEventListener('DOMContentLoaded', window.renderLucideIcons);
document.addEventListener('alpine:init', () => {
    queueMicrotask(window.renderLucideIcons);
});
