import { Link } from "react-router-dom";
import Header from "@/components/Header";
import Footer from "@/components/Footer";

const Privacy = () => {
  return (
    <div className="min-h-screen bg-background pt-16 flex flex-col">
      <Header />
      <header>
        <div className="container mx-auto px-4 py-4 max-w-4xl mt-5">
          <h1 className="text-3xl font-bold text-foreground">Privacy Policy</h1>
        </div>
      </header>

      <main className="container mx-auto px-4 py-8 max-w-4xl">
        <div className="prose prose-gray dark:prose-invert max-w-none">
          
          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">Introduction</h2>
            <p className="text-muted-foreground leading-relaxed">
              ViewsMax ("we," "our," or "us") is committed to protecting your privacy. This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you use our YouTube analytics and content optimization platform. By using our service, you agree to the practices described in this policy.
            </p>
          </section>

          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">Information We Collect</h2>
            
            <h3 className="text-xl font-medium mb-3">YouTube Data Access</h3>
            <p className="text-muted-foreground leading-relaxed mb-4">
              When you connect your YouTube channel to our platform, we request read-only access to:
            </p>
            <ul className="list-disc list-inside text-muted-foreground space-y-2 mb-4">
              <li>Your channel metadata (name, description, subscriber count, total views)</li>
              <li>Your video information (titles, descriptions, thumbnails, statistics, tags)</li>
              <li>Your playlists and their contents</li>
              <li>Analytics data including views over time, watch time, audience demographics, and geographic data</li>
            </ul>
            <p className="text-muted-foreground leading-relaxed">
              This data is accessed through Google's YouTube Data API v3 and YouTube Analytics API using OAuth 2.0 authentication with scopes: <code className="bg-muted px-2 py-1 rounded">youtube.readonly</code> and <code className="bg-muted px-2 py-1 rounded">yt-analytics.readonly</code>.
            </p>

            <h3 className="text-xl font-medium mb-3 mt-6">Account Information</h3>
            <p className="text-muted-foreground leading-relaxed">
              We collect basic account information including your email address, name, and Google profile information when you sign up using Google OAuth.
            </p>

            <h3 className="text-xl font-medium mb-3 mt-6">Usage Data</h3>
            <p className="text-muted-foreground leading-relaxed">
              We automatically collect information about how you use our platform, including pages visited, features used, and interaction patterns to improve our service.
            </p>
          </section>

          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">How We Use Your Information</h2>
            <p className="text-muted-foreground leading-relaxed mb-4">
              We use the collected information to:
            </p>
            <ul className="list-disc list-inside text-muted-foreground space-y-2">
              <li>Provide channel analytics and performance insights</li>
              <li>Generate AI-powered content recommendations and video reviews</li>
              <li>Display trending analysis and competitive intelligence</li>
              <li>Improve our algorithms and recommendation systems</li>
              <li>Provide customer support and respond to your inquiries</li>
              <li>Send service-related communications and updates</li>
              <li>Ensure platform security and prevent fraudulent activity</li>
            </ul>
          </section>

          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">Data Storage and Security</h2>
            
            <h3 className="text-xl font-medium mb-3">Client-Side Storage</h3>
            <p className="text-muted-foreground leading-relaxed mb-4">
              Your YouTube OAuth tokens and cached analytics data are stored locally in your browser's localStorage. This data remains on your device and is not transmitted to our servers.
            </p>

            <h3 className="text-xl font-medium mb-3">Server-Side Processing</h3>
            <p className="text-muted-foreground leading-relaxed mb-4">
              We use Supabase for secure data processing and storage. Any data processed on our servers is encrypted in transit and at rest. Our edge functions process video analysis requests using only public video IDs and metadata. We only share limited video metadata (title, description, and videoId) with OpenAI for content analysis. We never transmit personally identifiable Google account data or analytics data to third parties.
            </p>

            <h3 className="text-xl font-medium mb-3">Security Measures</h3>
            <p className="text-muted-foreground leading-relaxed">
              We implement industry-standard security measures including HTTPS encryption, secure OAuth flows, and regular security audits to protect your information.
            </p>
          </section>

          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">Limited Use of Google User Data</h2>
            <p className="text-muted-foreground leading-relaxed mb-4">
              We access Google user data solely to provide user-facing features in our app and in accordance with Google API Services User Data Policy, including the Limited Use requirements. We do not sell or transfer Google user data except as necessary to provide the service with your consent, for security, to comply with law, or as part of a business transfer with explicit user consent. We do not allow human access to Google user data unless you provide explicit consent or it is required for security, legal compliance, or troubleshooting at your request.
            </p>
            <ul className="list-disc list-inside text-muted-foreground space-y-2">
              <li>Scopes requested are limited to what is necessary: <code className="bg-muted px-2 py-1 rounded">youtube.readonly</code>, <code className="bg-muted px-2 py-1 rounded">yt-analytics.readonly</code>, and <code className="bg-muted px-2 py-1 rounded">userinfo.profile</code>.</li>
              <li>No ads personalization, retargeting, or data broker transfers using Google user data.</li>
              <li>Data is secured in transit and at rest and access is restricted.</li>
            </ul>
            <p className="text-muted-foreground leading-relaxed mt-4">
              <strong className="text-foreground">Consent requirement:</strong> Users must agree to this Privacy Policy and the Terms of Service before accessing features powered by Google/YouTube APIs. Consent is recorded with a policy version and re-confirmed when policies change.
            </p>
          </section>

          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">Data Sharing and Disclosure</h2>
            <p className="text-muted-foreground leading-relaxed mb-4">
              We do not sell, trade, or rent your personal information to third parties. We may share your information only in the following circumstances:
            </p>
            <ul className="list-disc list-inside text-muted-foreground space-y-2">
              <li><strong>Service Providers:</strong> We may share data with trusted third-party services (like OpenAI for content analysis) that help us operate our platform</li>
              <li><strong>Legal Requirements:</strong> When required by law, court order, or government regulation</li>
              <li><strong>Business Transfers:</strong> In connection with any merger, acquisition, or sale of assets</li>
              <li><strong>Consent:</strong> When you explicitly consent to sharing your information</li>
            </ul>
          </section>

          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">Your Rights and Controls</h2>
            
            <h3 className="text-xl font-medium mb-3">Access and Disconnect</h3>
            <p className="text-muted-foreground leading-relaxed mb-4">
              You can disconnect your YouTube channel at any time from within our platform. This will immediately stop all data access and clear your stored tokens and cached data.
            </p>

            <h3 className="text-xl font-medium mb-3">Google Account Permissions</h3>
            <p className="text-muted-foreground leading-relaxed mb-4">
              You can revoke our access to your YouTube data at any time through your Google Account settings at <a href="https://myaccount.google.com/permissions" className="text-primary hover:underline" target="_blank" rel="noopener noreferrer">myaccount.google.com/permissions</a>.
            </p>

            <h3 className="text-xl font-medium mb-3">Data Portability</h3>
            <p className="text-muted-foreground leading-relaxed">
              You can export your analytics data and insights from our platform at any time through your dashboard.
            </p>
            
          </section>

          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">Data Retention and Deletion</h2>
            <p className="text-muted-foreground leading-relaxed mb-4">
              Users may delete their data directly from the Dashboard at any time via <Link to="/dashboard/settings" className="text-primary hover:underline">Settings</Link>. Alternatively, they may email us at <a href="mailto:admin@iclicksee.com" className="text-primary hover:underline">admin@iclicksee.com</a> to request deletion. All requests are honored within 30 days.
            </p>
            <ul className="list-disc list-inside text-muted-foreground space-y-2 mb-4">
              <li>We retain Google user data only as long as needed to provide the service.</li>
              <li>Upon your request or if authorization is revoked, we delete Google user data promptly and within 30 days.</li>
              <li>Local tokens and caches are cleared immediately when you disconnect or revoke access.</li>
            </ul>
            <p className="text-muted-foreground leading-relaxed">
              To request deletion beyond the controls above, contact us at the email below. We will complete deletion within 30 days of receipt.
            </p>
          </section>

          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">Cookies and Tracking</h2>
            <p className="text-muted-foreground leading-relaxed">
              We use essential cookies and local storage to maintain your session, remember your preferences, and provide core functionality. We do not use third-party tracking cookies or advertising cookies.
            </p>
          </section>

          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">Children's Privacy</h2>
            <p className="text-muted-foreground leading-relaxed">
              Our service is not intended for children under 13 years of age. We do not knowingly collect personal information from children under 13. If you are a parent or guardian and believe your child has provided us with personal information, please contact us.
            </p>
          </section>

          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">International Data Transfers</h2>
            <p className="text-muted-foreground leading-relaxed">
              Your information may be transferred to and processed in countries other than your own. We ensure that such transfers comply with applicable data protection laws and that adequate safeguards are in place.
            </p>
          </section>

          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">Changes to This Policy</h2>
            <p className="text-muted-foreground leading-relaxed">
              We may update this Privacy Policy from time to time. We will notify users of material changes via email or in-app notification, and by posting the new Privacy Policy on this page and updating the "Last updated" date. We encourage you to review this Privacy Policy periodically.
            </p>
          </section>

          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">Contact Us</h2>
            <p className="text-muted-foreground leading-relaxed mb-4">
              If you have any questions about this Privacy Policy or our data practices, please contact us:
            </p>
            <div className="bg-muted p-4 rounded-lg">
              <p className="text-muted-foreground">
                <strong>Email:</strong> admin@iclicksee.com<br/>
                <strong>Address:</strong> Forma House, 40 Bowling Green Lane, London, UK, EC1R 0NE<br/>
                <strong>Support:</strong> Available via email at admin@iclicksee.com
              </p>
            </div>
          </section>

          <section className="mb-8 bg-blue-50 dark:bg-blue-950/20 p-6 rounded-lg">
            <h2 className="text-2xl font-semibold mb-4">YouTube API Services</h2>
            <p className="text-muted-foreground leading-relaxed mb-4">
              Our application uses YouTube API Services. By using our service, you are also bound by the YouTube Terms of Service, which can be found at: <a href="https://www.youtube.com/t/terms" className="text-primary hover:underline" target="_blank" rel="noopener noreferrer">https://www.youtube.com/t/terms</a>
            </p>
            <p className="text-muted-foreground leading-relaxed">
              You can revoke our access to your data via the Google security settings page at: <a href="https://security.google.com/settings/security/permissions" className="text-primary hover:underline" target="_blank" rel="noopener noreferrer">https://security.google.com/settings/security/permissions</a>. Please also review the Google Privacy Policy at <a href="https://policies.google.com/privacy" className="text-primary hover:underline" target="_blank" rel="noopener noreferrer">https://policies.google.com/privacy</a>.
            </p>
            <p className="text-muted-foreground leading-relaxed mt-4">
              For details on how we access, use, and handle Google user data, see the Google API Services User Data Policy at <a href="https://developers.google.com/terms/api-services-user-data-policy" className="text-primary hover:underline" target="_blank" rel="noopener noreferrer">developers.google.com/terms/api-services-user-data-policy</a> and its <a href="https://developers.google.com/terms/api-services-user-data-policy#additional_requirements_for_specific_api_scopes" className="text-primary hover:underline" target="_blank" rel="noopener noreferrer">Additional Requirements for Specific API Scopes</a>. Also review the <a href="https://developers.google.com/youtube/terms/developer-policies#:~:text=General%20Developer%20Policies-,A.,The%20privacy%20policy%20must:" className="text-primary hover:underline" target="_blank" rel="noopener noreferrer">YouTube API Services Developer Policies</a>.
            </p>
          </section>

        </div>
      </main>
      <Footer />
    </div>
  );
};

export default Privacy;

