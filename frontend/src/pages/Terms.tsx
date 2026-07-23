import { Link } from "react-router-dom";
import Header from "@/components/Header";
import Footer from "@/components/Footer";

const Terms = () => {
  return (
    <div className="min-h-screen bg-background pt-16 flex flex-col">
      <Header />
      <header>
        <div className="container mx-auto px-4 py-4 max-w-4xl mt-5">
          <h1 className="text-3xl font-bold text-foreground">Terms of Service</h1>
        </div>
      </header>

      <main className="container mx-auto px-4 py-8 max-w-4xl">
        <div className="prose prose-gray dark:prose-invert max-w-none">
          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">Introduction</h2>
            <p className="text-muted-foreground leading-relaxed">
              These Terms of Service ("Terms") govern your access to and use of ViewsMax ("we", "our", or "us"). By accessing or using our platform, you agree to be bound by these Terms.
            </p>
            <p className="text-muted-foreground leading-relaxed mt-4">
              By using ViewsMax, you also agree to be bound by the <a href="https://www.youtube.com/t/terms" className="text-primary hover:underline" target="_blank" rel="noopener noreferrer">YouTube Terms of Service</a>.
            </p>
          </section>

          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">Use of the Service</h2>
            <p className="text-muted-foreground leading-relaxed">
              You are responsible for ensuring that your use of the Service complies with all applicable laws and third-party terms, including the <a href="https://www.youtube.com/t/terms" className="text-primary hover:underline" target="_blank" rel="noopener noreferrer">YouTube Terms of Service</a> and Google’s policies referenced below.
            </p>
          </section>

          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">Accounts and Access</h2>
            <ul className="list-disc list-inside text-muted-foreground space-y-2">
              <li>You are responsible for maintaining the confidentiality of your account.</li>
              <li>You must provide accurate information and notify us of any unauthorized use.</li>
              <li>We may suspend or terminate access for conduct that violates these Terms.</li>
            </ul>
          </section>

          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">Subscriptions and Billing</h2>
            <p className="text-muted-foreground leading-relaxed">
              Certain features may require a paid subscription. Fees, billing cycles, and cancellation terms will be presented at checkout. Charges are non‑refundable except where required by law.
            </p>
          </section>

          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">Acceptable Use</h2>
            <ul className="list-disc list-inside text-muted-foreground space-y-2">
              <li>No scraping, reverse engineering, or circumvention of security.</li>
              <li>No uploading of malicious code or interference with the Service.</li>
              <li>No use of the Service to infringe intellectual property rights.</li>
            </ul>
          </section>

          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">Intellectual Property</h2>
            <p className="text-muted-foreground leading-relaxed">
              We own all rights, title, and interest in the Service, including software, content, and trademarks. You retain ownership of your content. By submitting content, you grant us a limited license to host and process it solely to provide the Service.
            </p>
          </section>

          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">Disclaimer and Limitation of Liability</h2>
            <p className="text-muted-foreground leading-relaxed">
              The Service is provided on an "as is" basis. To the maximum extent permitted by law, we disclaim all warranties and are not liable for indirect, incidental, or consequential damages.
            </p>
          </section>

          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">Termination</h2>
            <p className="text-muted-foreground leading-relaxed">
              We may suspend or terminate your access at any time for violation of these Terms or to comply with legal obligations. Upon termination, your right to use the Service ceases immediately.
            </p>
          </section>

          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">Disconnect and Data Deletion</h2>
            <p className="text-muted-foreground leading-relaxed">
              You may disconnect your YouTube account at any time at
              {" "}
              <a href="https://myaccount.google.com/permissions" className="text-primary hover:underline" target="_blank" rel="noopener noreferrer">Google Account Permissions</a>.
              {" "}
              You may also request deletion of any data stored by ViewsMax that was obtained via Google APIs by contacting us at
              {" "}
              <a href="mailto:admin@iclicksee.com" className="text-primary hover:underline">admin@iclicksee.com</a>.
              {" "}
              Alternatively, you can manage disconnection and request deletion from within the Service via
              {" "}
              <Link to="/dashboard/settings" className="text-primary hover:underline">Settings</Link>.
            </p>
          </section>

          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">Changes to These Terms</h2>
            <p className="text-muted-foreground leading-relaxed">
              We may modify these Terms from time to time. Changes will be effective when posted on this page, and we may also notify you by email or through the Service where required by law. Your continued use of the Service constitutes acceptance of the revised Terms.
            </p>
          </section>

          <section className="mb-8 bg-blue-50 dark:bg-blue-950/20 p-6 rounded-lg">
            <h2 className="text-2xl font-semibold mb-4">Third‑Party Terms and API Use</h2>
            <p className="text-muted-foreground leading-relaxed mb-4">
              ViewsMax integrates with Google APIs, including YouTube API Services. Your use of YouTube features is subject to the following policies, which you agree to follow when using the Service:
            </p>
            <ul className="list-disc list-inside text-muted-foreground space-y-2 mb-4">
              <li><a href="https://www.youtube.com/t/terms" className="text-primary hover:underline" target="_blank" rel="noopener noreferrer">YouTube Terms of Service</a></li>
              <li><a href="https://developers.google.com/terms" className="text-primary hover:underline" target="_blank" rel="noopener noreferrer">Google APIs Terms of Service</a></li>
              <li><a href="https://developers.google.com/terms/api-services-user-data-policy" className="text-primary hover:underline" target="_blank" rel="noopener noreferrer">Google API Services User Data Policy (Limited Use)</a></li>
              <li><a href="https://developers.google.com/terms/api-services-user-data-policy#additional_requirements_for_specific_api_scopes" className="text-primary hover:underline" target="_blank" rel="noopener noreferrer">Additional Requirements for Specific API Scopes</a></li>
              <li><a href="https://developers.google.com/youtube/terms/developer-policies#:~:text=General%20Developer%20Policies-,A.,The%20privacy%20policy%20must:" className="text-primary hover:underline" target="_blank" rel="noopener noreferrer">YouTube API Services Developer Policies</a></li>
              <li><a href="https://policies.google.com/privacy" className="text-primary hover:underline" target="_blank" rel="noopener noreferrer">Google Privacy Policy</a></li>
            </ul>
            <p className="text-muted-foreground leading-relaxed mb-4">
              We request only the read‑only YouTube scopes necessary to provide user‑facing features: <code className="bg-muted px-2 py-1 rounded">youtube.readonly</code> and <code className="bg-muted px-2 py-1 rounded">yt-analytics.readonly</code>. We use Google user data solely to provide or improve user‑facing features in accordance with the Limited Use requirements; we do not sell Google user data, we do not transfer it to third parties except as necessary to provide the Service with your consent or as required by law, we do not use it for advertising or retargeting, and we do not allow human access except with your consent or for security, compliance, or troubleshooting.
            </p>
            <p className="text-muted-foreground leading-relaxed">
              You may revoke our access to your Google account data at any time at <a href="https://myaccount.google.com/permissions" className="text-primary hover:underline" target="_blank" rel="noopener noreferrer">myaccount.google.com/permissions</a>.
            </p>
            <p className="text-muted-foreground leading-relaxed mt-4">
              <strong className="text-foreground">Consent requirement:</strong> Users must agree to the Privacy Policy and these Terms before accessing features powered by Google/YouTube APIs. Consent is recorded with a policy version and re‑confirmed when policies change.
            </p>
          </section>

          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">International Data Transfers</h2>
            <p className="text-muted-foreground leading-relaxed">
              If you access our Service from outside the UK, note that your data may be transferred to and processed in other jurisdictions. We take appropriate safeguards, such as Standard Contractual Clauses, to protect your data.
            </p>
          </section>

          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">Support</h2>
            <p className="text-muted-foreground leading-relaxed">
              Support is available by contacting us at <a href="mailto:admin@iclicksee.com" className="text-primary hover:underline">admin@iclicksee.com</a>.
            </p>
          </section>

          <section className="mb-8">
            <h2 className="text-2xl font-semibold mb-4">Contact Us</h2>
            <div className="bg-muted p-4 rounded-lg">
              <p className="text-muted-foreground">
                <strong>Email:</strong> admin@iclicksee.com<br/>
                <strong>Address:</strong> Forma House, 40 Bowling Green Lane, London, UK, EC1R 0NE
              </p>
            </div>
          </section>
        </div>
      </main>
      <Footer />
    </div>
  );
};

export default Terms;


