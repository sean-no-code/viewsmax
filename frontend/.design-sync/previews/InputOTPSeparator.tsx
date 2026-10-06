import { InputOTP, InputOTPGroup, InputOTPSeparator, InputOTPSlot, Label } from "@viewsmax/ui";

export const InsideInputOTP = () => (
  <div className="grid gap-3">
    <Label htmlFor="otp-separator">Enter the 6-digit code we emailed you</Label>
    <InputOTP id="otp-separator" maxLength={6} defaultValue="482">
      <InputOTPGroup>
        <InputOTPSlot index={0} />
        <InputOTPSlot index={1} />
        <InputOTPSlot index={2} />
      </InputOTPGroup>
      <InputOTPSeparator />
      <InputOTPGroup>
        <InputOTPSlot index={3} />
        <InputOTPSlot index={4} />
        <InputOTPSlot index={5} />
      </InputOTPGroup>
    </InputOTP>
  </div>
);
