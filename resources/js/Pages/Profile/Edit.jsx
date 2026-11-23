import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

export default function Edit({ mustVerifyEmail, status, jobSites, primaryJobSiteId }) {
    return (
        <AppLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Profile
                </h2>
            }
        >
            <Head title="Profile" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <div className="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <UpdateProfileInformationForm
                            mustVerifyEmail={mustVerifyEmail}
                            status={status}
                            className="max-w-xl"
                        />
                    </div>

                    <div className="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <section aria-labelledby="assigned-job-sites">
                            <h3 id="assigned-job-sites" className="text-lg font-medium text-gray-900">
                                Assigned Job Sites
                            </h3>
                            {jobSites.length > 0 ? (
                                <ul className="mt-4 divide-y divide-gray-200">
                                    {jobSites.map((site) => (
                                        <li key={site.id} className="py-3">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-medium text-gray-900">{site.name}</span>
                                                {site.id === primaryJobSiteId && (
                                                    <span className="rounded-full bg-blue-50 px-2 py-1 text-xs font-medium text-blue-800">
                                                        Primary job site
                                                    </span>
                                                )}
                                            </div>
                                            {site.address && <p className="mt-1 text-sm text-gray-600">{site.address}</p>}
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="mt-2 text-sm text-gray-600">You have no assigned job sites.</p>
                            )}
                        </section>
                    </div>

                    <div className="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <UpdatePasswordForm className="max-w-xl" />
                    </div>

                </div>
            </div>
        </AppLayout>
    );
}
