import { InputOTP, InputOTPGroup, InputOTPSeparator, InputOTPSlot, Label } from "@viewsmax/ui";

export const VerifyCode = () => (
  <div className="grid gap-3">
    <Label htmlFor="verify-code">Enter the 6-digit code we emailed you</Label>
    <InputOTP id="verify-code" maxLength={6} defaultValue="482">
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
    <p className="text-xs text-muted-foreground">Code expires in 10 minutes.</p>
  </div>
);

export const Complete = () => (
  <div className="grid gap-3">
    <Label htmlFor="verify-complete">Verification code</Label>
    <InputOTP id="verify-complete" maxLength={6} defaultValue="482913">
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
    <p className="text-xs text-up">Code accepted. Connecting your YouTube channel…</p>
  </div>
);

export const Disabled = () => (
  <div className="grid gap-3">
    <Label htmlFor="verify-disabled">Verification code</Label>
    <InputOTP id="verify-disabled" maxLength={6} defaultValue="48" disabled>
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
    <p className="text-xs text-muted-foreground">Too many attempts. Request a new code in 0:42.</p>
  </div>
);
