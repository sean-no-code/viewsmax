import { useEffect, useState, useCallback } from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogTrigger,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogClose,
} from "@/components/ui/dialog";
import { toast } from "sonner";
import { useTranslation } from "react-i18next";
import { Switch } from "@/components/ui/switch";
import { youtubeAuthService } from "@/lib/youtube-auth";
import { viewsMaxApi } from "@/lib/api-service";
import { supabase } from "@/integrations/supabase/client";
import { useAuth } from "@/hooks/useAuth";
import ConnectAccounts from "@/components/ConnectAccounts";
import AiAssistantAccess from "@/components/AiAssistantAccess";
import AiActivityLog from "@/components/AiActivityLog";

interface BackendChannel {
  id: number;
  youtube_channel_id: string;
  channel_name: string;
  channel_description?: string;
  subscriber_count: number;
  video_count: number;
  view_count: number;
  profile_image_url?: string;
  custom_url?: string;
  country?: string;
  published_at?: string;
}

const Settings = () => {
  const { t } = useTranslation();
  const { user } = useAuth();
  const [isDeleting, setIsDeleting] = useState(false);
  const [isDisconnecting, setIsDisconnecting] = useState(false);
  const [isConnected, setIsConnected] = useState(false);
  const [channelName, setChannelName] = useState<string | null>(null);
  const [backendChannels, setBackendChannels] = useState<BackendChannel[]>([]);
  const [notifyPostFailures, setNotifyPostFailures] = useState<boolean | null>(null);
  const [savingNotify, setSavingNotify] = useState(false);

  // Load notification preferences
  useEffect(() => {
    let cancelled = false;
    viewsMaxApi.getUserSettings().then((res) => {
      if (!cancelled && res.success && res.data) {
        setNotifyPostFailures(res.data.notify_post_failures);
      }
    });
    return () => {
      cancelled = true;
    };
  }, []);

  const handleNotifyToggle = async (checked: boolean) => {
    setNotifyPostFailures(checked);
    setSavingNotify(true);
    const res = await viewsMaxApi.updateUserSettings({ notify_post_failures: checked });
    setSavingNotify(false);
    if (!res.success) {
      setNotifyPostFailures(!checked); // revert optimistic flip
      toast.error(t("settings.notifications.saveFailed", "Couldn't save the notification setting. Please try again."));
      return;
    }
    toast.success(checked
      ? t("settings.notifications.enabled", "You'll be emailed when a post fails.")
      : t("settings.notifications.disabled", "Publish-failure emails turned off."));
  };


  // Fetch backend channels to check connection status
  const fetchBackendChannels = useCallback(async () => {
    if (!user) return;

    try {
      const storedSession = localStorage.getItem('auth_session');
      let token = null;
      if (storedSession) {
        try {
          const session = JSON.parse(storedSession);
          token = session.token;
        } catch (error) {
          console.error('Error parsing auth session:', error);
        }
      }

      if (!token) {
        setIsConnected(false);
        setChannelName(null);
        return;
      }

      const response = await viewsMaxApi.getChannels(token);
      if (response.success && response.data) {
        setBackendChannels(response.data);
        setIsConnected(response.data.length > 0);
        if (response.data.length > 0) {
          setChannelName(response.data[0].channel_name);
        } else {
          setChannelName(null);
        }
      } else {
        setIsConnected(false);
        setChannelName(null);
      }
    } catch (error) {
      console.error('Failed to fetch backend channels:', error);
      setIsConnected(false);
      setChannelName(null);
    }
  }, [user]);

  useEffect(() => {
    fetchBackendChannels();
  }, [fetchBackendChannels]);

  const handleDisconnect = async () => {
    if (backendChannels.length === 0) {
      toast.error(t("settings.account.noChannels", "No channels to disconnect"));
      return;
    }

    setIsDisconnecting(true);
    try {
      // Get token from auth session
      const storedSession = localStorage.getItem('auth_session');
      let token = null;
      if (storedSession) {
        try {
          const session = JSON.parse(storedSession);
          token = session.token;
        } catch (error) {
          console.error('Error parsing auth session:', error);
        }
      }

      if (!token) {
        toast.error(t("settings.account.authRequired", "Authentication required"));
        return;
      }

      // Disconnect all channels
      let successCount = 0;
      let failCount = 0;

      for (const channel of backendChannels) {
        const response = await viewsMaxApi.disconnectChannel(token, channel.id);
        if (response.success) {
          successCount++;
        } else {
          failCount++;
        }
      }

      // Clear local state
      youtubeAuthService.disconnect();
      
      // Clear cached data
      try {
        localStorage.removeItem('youtube_analytics_data');
        localStorage.removeItem('youtube_analytics_timestamp');
        localStorage.removeItem('youtube_cache_channel_id');
        localStorage.removeItem('youtube_oauth_code');
      } catch (e) {
        console.debug('Failed to clear cached data', e);
      }

      // Refresh channel list
      await fetchBackendChannels();

      if (successCount > 0) {
        toast.success(t("settings.account.youtubeDisconnected", "YouTube disconnected and Google access revoked"));
        setIsConnected(false);
        setChannelName(null);
      }
      if (failCount > 0) {
        toast.error(t("settings.account.disconnectChannelsFailed", { count: failCount, defaultValue_one: "Failed to disconnect {{count}} channel(s)", defaultValue_other: "Failed to disconnect {{count}} channel(s)" }));
      }
    } catch (e) {
      console.error('Error disconnecting channels:', e);
      toast.error(t("settings.account.disconnectFailed", "Could not disconnect. Please try again."));
    } finally {
      setIsDisconnecting(false);
    }
  };

  const handleDeleteData = async () => {
    setIsDeleting(true);
    try {
      // Delete all user data
      const result = await viewsMaxApi.deleteAllUserData();
      if (!result.success) {
        throw new Error(result.error || 'Failed to delete user data');
      }

      // Clear the cached analytics and local YouTube state the backend just deleted
      try {
        localStorage.removeItem('youtube_analytics_data');
        localStorage.removeItem('youtube_analytics_timestamp');
        localStorage.removeItem('youtube_cache_channel_id');
        localStorage.removeItem('youtube_oauth_code');
        // Clear any locally cached privacy consent keys
        try {
          Object.keys(localStorage).forEach((key) => {
            if (key.startsWith('tm_privacy_consent') || key.includes('ViewsMax_privacy_consent')) {
              localStorage.removeItem(key);
            }
          });
        } catch (e) {
          console.debug('Failed to clear local privacy consent keys', e);
        }
      } catch (e) {
        console.debug('Failed to clear some cached analytics items', e);
      }

      youtubeAuthService.disconnect();
      await fetchBackendChannels();
      toast.success(t("settings.account.dataCleared", "Your YouTube data was deleted and ViewsMax's Google access revoked."));
    } catch (error) {
      console.error('Delete data error:', error);
      toast.error(t("settings.account.clearFailed", "Could not delete your data. Please try again."));
    } finally {
      setIsDeleting(false);
    }
  };

  return (
    <div className="space-y-6 max-w-3xl mx-auto">
      {/* NOTE: The Connected Accounts card is commented out while platforms are
          connected from the Connections page. Restore when ready.
      <Card>
        <CardHeader>
          <CardTitle>Connected Accounts</CardTitle>
          <CardDescription>Connect or disconnect your video platforms.</CardDescription>
        </CardHeader>
        <CardContent>
          <ConnectAccounts mode="settings" />
        </CardContent>
      </Card>
      */}

      <Card>
        <CardHeader>
          <CardTitle>{t("settings.notifications.title", "Notifications")}</CardTitle>
          <CardDescription>{t("settings.notifications.description", "Choose when ViewsMax emails you.")}</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex items-center justify-between">
            <div>
              <div className="font-medium">{t("settings.notifications.postFailures", "Email me when a post fails to publish")}</div>
              <div className="text-sm text-muted-foreground">
                {t("settings.notifications.postFailuresHint", "One email per failed post listing every platform that failed, with a link to retry.")}
              </div>
            </div>
            <Switch
              checked={notifyPostFailures ?? true}
              disabled={notifyPostFailures === null || savingNotify}
              onCheckedChange={handleNotifyToggle}
              aria-label={t("settings.notifications.postFailures", "Email me when a post fails to publish")}
            />
          </div>
        </CardContent>
      </Card>


      <AiAssistantAccess />

      <AiActivityLog />

      <Card>
        <CardHeader>
          <CardTitle>{t("settings.account.title", "Account & Data")}</CardTitle>
          <CardDescription>{t("settings.account.description", "Manage your YouTube connection and the data ViewsMax stores from it.")}</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex items-center justify-between gap-4">
            <div>
              <div className="font-medium">{t("settings.account.disconnectYoutube", "Disconnect YouTube")}</div>
              <div className="text-sm text-muted-foreground">
                {t("settings.account.disconnectYoutubeHint", "Revokes ViewsMax's access in your Google account and deletes the stored tokens, your connected channels and their imported videos.")}
              </div>
            </div>
            <Button onClick={handleDisconnect} disabled={!isConnected || isDisconnecting} variant="outline">
              {isConnected
                ? (isDisconnecting ? t("settings.account.disconnecting", "Disconnecting…") : t("settings.account.disconnect", "Disconnect"))
                : t("settings.account.notConnected", "Not connected")}
            </Button>
          </div>

          <div className="flex items-center justify-between gap-4">
            <div>
              <div className="font-medium">{t("settings.account.deleteData", "Delete My Data")}</div>
              <div className="text-sm text-muted-foreground">
                {t("settings.account.deleteDataHint", "Deletes every connected YouTube channel with its imported videos and analytics, all stored Google tokens, and revokes ViewsMax's access in your Google account. Your ViewsMax account, posts and offers stay.")}
              </div>
            </div>
            <Dialog>
              <DialogTrigger asChild>
                <Button disabled={isDeleting} variant="destructive">
                  {isDeleting ? t("settings.account.deleting", "Deleting…") : t("settings.account.deleteDataButton", "Delete Data")}
                </Button>
              </DialogTrigger>
              <DialogContent>
                <DialogHeader>
                  <DialogTitle>{t("settings.account.deleteConfirmTitle", "Are you absolutely sure?")}</DialogTitle>
                  <DialogDescription>
                    {t("settings.account.deleteConfirmBody", "This permanently deletes your connected YouTube channels, their imported videos and analytics, and all stored Google tokens, and revokes ViewsMax's access in your Google account. This cannot be undone; you can reconnect YouTube afterwards.")}
                  </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                  <DialogClose asChild>
                    <Button variant="outline">{t("settings.account.cancel", "Cancel")}</Button>
                  </DialogClose>
                  <DialogClose asChild>
                    <Button onClick={handleDeleteData} variant="destructive">
                      {t("settings.account.deleteConfirmButton", "Yes, delete my data")}
                    </Button>
                  </DialogClose>
                </DialogFooter>
              </DialogContent>
            </Dialog>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default Settings;


