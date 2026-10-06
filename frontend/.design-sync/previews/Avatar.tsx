import { Avatar, AvatarFallback, AvatarImage } from "@viewsmax/ui";

export const WithImage = () => (
  <div className="flex items-center gap-3">
    <Avatar>
      <AvatarImage src="https://i.pravatar.cc/80?img=12" alt="Maya Chen" />
      <AvatarFallback>MC</AvatarFallback>
    </Avatar>
    <div className="text-sm">
      <p className="font-medium">Maya Chen</p>
      <p className="text-muted-foreground">@mayamakes · YouTube</p>
    </div>
  </div>
);

export const FallbackOnly = () => (
  <div className="flex items-center gap-3">
    <Avatar>
      <AvatarFallback>JR</AvatarFallback>
    </Avatar>
    <Avatar>
      <AvatarFallback className="bg-vm-red text-white">VM</AvatarFallback>
    </Avatar>
    <Avatar>
      <AvatarFallback className="bg-data-violet text-white">TK</AvatarFallback>
    </Avatar>
  </div>
);

export const Sizes = () => (
  <div className="flex items-end gap-3">
    <Avatar className="h-6 w-6">
      <AvatarFallback className="text-xs">SF</AvatarFallback>
    </Avatar>
    <Avatar>
      <AvatarFallback>SF</AvatarFallback>
    </Avatar>
    <Avatar className="h-14 w-14">
      <AvatarFallback className="text-lg">SF</AvatarFallback>
    </Avatar>
    <Avatar className="h-20 w-20">
      <AvatarFallback className="text-2xl">SF</AvatarFallback>
    </Avatar>
  </div>
);

export const ConnectedAccounts = () => (
  <div className="flex items-center gap-3">
    <div className="flex items-center gap-1">
      <Avatar className="h-8 w-8 border-2 border-background">
        <AvatarImage src="https://i.pravatar.cc/80?img=5" alt="YouTube channel" />
        <AvatarFallback className="text-xs">YT</AvatarFallback>
      </Avatar>
      <Avatar className="h-8 w-8 border-2 border-background">
        <AvatarImage src="https://i.pravatar.cc/80?img=32" alt="TikTok account" />
        <AvatarFallback className="text-xs">TT</AvatarFallback>
      </Avatar>
      <Avatar className="h-8 w-8 border-2 border-background">
        <AvatarImage src="https://i.pravatar.cc/80?img=47" alt="Instagram account" />
        <AvatarFallback className="text-xs">IG</AvatarFallback>
      </Avatar>
      <Avatar className="h-8 w-8 border-2 border-background">
        <AvatarFallback className="text-xs">+2</AvatarFallback>
      </Avatar>
    </div>
    <p className="text-sm text-muted-foreground">5 connected accounts</p>
  </div>
);
