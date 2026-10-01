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
              ViewsMax ("we," "our," or "us") is committed to protecting your privacy. This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you use our platform for publishing social content, tracking offers, and researching and analyzing videos. By using our service, you agree to the practices described in this policy.
            </p>
          </section>

          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">Information We Collect</h2>
            
            <h3 className="text-xl font-medium mb-3">YouTube Data Access</h3>
            <p className="text-muted-foreground leading-relaxed mb-4">
              When you connect your YouTube channel to our platform, we request access to:
            </p>
            <ul className="list-disc list-inside text-muted-foreground space-y-2 mb-4">
              <li>Your channel metadata (name, description, subscriber count, total views)</li>
              <li>Your video information (titles, descriptions, thumbnails, statistics, tags)</li>
              <li>Your playlists and their contents</li>
              <li>Analytics data including views over time, watch time, audience demographics, and geographic data</li>
              <li>Uploading videos to your channel, used only when you publish or schedule a post to YouTube</li>
            </ul>
            <p className="text-muted-foreground leading-relaxed">
              This data is accessed through Google's YouTube Data API v3 and YouTube Analytics API using OAuth 2.0 authentication with scopes: <code className="bg-muted px-2 py-1 rounded">youtube.readonly</code>, <code className="bg-muted px-2 py-1 rounded">yt-analytics.readonly</code>, and <code className="bg-muted px-2 py-1 rounded">youtube.upload</code>.
            </p>

            <h3 className="text-xl font-medium mb-3 mt-6">Account Information</h3>
            <p className="text-muted-foreground leading-relaxed">
              We collect basic account information including your email address, name, and Google profile information when you sign up using Google OAuth.
            </p>

            <h3 className="text-xl font-medium mb-3 mt-6">Usage Data</h3>
            <p className="text-muted-foreground leading-relaxed">
              We automatically collect information about how you use our platform, including pages visited, features used, and interaction patterns to improve our service.
            </p>

            <h3 className="text-xl font-medium mb-3 mt-6">Connected Social Accounts</h3>
            <p className="text-muted-foreground leading-relaxed">
              When you connect a social account (YouTube, TikTok, X, LinkedIn, Threads, Instagram, or Bluesky), we store the account's name and the access tokens that platform gives us. We use them only to publish, schedule, and check the status of posts you create. Disconnecting an account from the Connections page deletes its stored tokens.
            </p>
          </section>

          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">AI Assistant Connections</h2>
            <p className="text-muted-foreground leading-relaxed mb-4">
              You can connect AI assistants such as Claude and ChatGPT to your ViewsMax account, by signing in to ViewsMax and approving access or by using an API key. A connected assistant can then read and act on your ViewsMax data on your behalf: your posts and schedule, offers and their statistics, connected account names, and saved outliers. It can publish posts to your connected social accounts when you ask it to.
            </p>
            <ul className="list-disc list-inside text-muted-foreground space-y-2 mb-4">
              <li><strong>What we receive:</strong> only the tool requests the assistant sends to ViewsMax, such as "create a post" with its caption. We do not receive your conversation with the assistant.</li>
              <li><strong>Activity log:</strong> for each tool request we record the tool name, the inputs the assistant sent, whether it succeeded, how the assistant signed in, and the time. You can view this log under Settings → AI Assistant Access.</li>
              <li><strong>Access limits:</strong> each sign-in is valid for one hour and renews automatically; if an assistant isn't used for 30 days, you need to sign in again. Read-only access cannot change anything in your account.</li>
              <li><strong>Plans and billing:</strong> AI assistants cannot view or change your plan or payment details.</li>
            </ul>
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
            
            <h3 className="text-xl font-medium mb-3">Account Tokens</h3>
            <p className="text-muted-foreground leading-relaxed mb-4">
              The access tokens for your connected accounts (YouTube and other social platforms) are stored encrypted on our servers, so we can publish and schedule the posts you create. They are deleted when you disconnect the account.
            </p>

            <h3 className="text-xl font-medium mb-3">Server-Side Processing</h3>
            <p className="text-muted-foreground leading-relaxed mb-4">
              Data processed on our servers is encrypted in transit. AI features send AI providers only the content needed for the task, such as a video's title, description, or transcript. We never transmit personally identifiable Google account data or analytics data to third parties.
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
              <li>Scopes requested are limited to what is necessary: <code className="bg-muted px-2 py-1 rounded">youtube.readonly</code>, <code className="bg-muted px-2 py-1 rounded">yt-analytics.readonly</code>, <code className="bg-muted px-2 py-1 rounded">youtube.upload</code>, and <code className="bg-muted px-2 py-1 rounded">userinfo.profile</code>.</li>
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
              <li><strong>Social Platforms:</strong> When you publish or schedule a post, its content and media are sent to the social platforms you chose</li>
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
              <li>Stored tokens are deleted when you disconnect an account.</li>
              <li>The AI assistant activity log is kept while your account exists and is deleted with your account.</li>
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

