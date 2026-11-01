import { reactive } from 'vue';

export const residentAccount = reactive({
    loaded: false,
    reportingOpen: false,
    photoUrl: null,
    photoNotice: '',
    photoNoticeError: false,
});

export function applyResidentAccount(profile) {
    if (!profile) return;
    residentAccount.loaded = true;
    residentAccount.reportingOpen = profile.reporting_open === true;
    residentAccount.photoUrl = profile.profile_photo_url || null;
}
