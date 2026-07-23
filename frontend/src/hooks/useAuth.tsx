import { useState, useEffect, createContext, useContext, ReactNode } from 'react';
import { viewsMaxApi } from '@/lib/api-service';

interface CustomUser {
  id: number | string;
  name: string;
  email: string;
  created_at?: string;
  public_id?: string;
  email_verified_at?: string | null;
  onboarding_completed_at?: string | null;
  connections_count?: number;
  has_active_subscription?: boolean;
}

interface CustomSession {
  user: CustomUser;
  token: string;
  token_type: string;
}

interface AuthContextType {
  user: CustomUser | null;
  session: CustomSession | null;
  loading: boolean;
  signOut: () => Promise<void>;
  setAuthData: (data: { user: CustomUser; token: string; token_type: string }) => void;
  refreshUser: () => Promise<void>;
}

const AuthContext = createContext<AuthContextType>({
  user: null,
  session: null,
  loading: true,
  signOut: async () => {},
  setAuthData: () => {},
  refreshUser: async () => {},
});

export const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
};

interface AuthProviderProps {
  children: ReactNode;
}

export const AuthProvider = ({ children }: AuthProviderProps) => {
  const [user, setUser] = useState<CustomUser | null>(null);
  const [session, setSession] = useState<CustomSession | null>(null);
  const [loading, setLoading] = useState(true);

  const setAuthData = (data: { user: CustomUser; token: string; token_type: string }) => {
    const authSession = {
      user: data.user,
      token: data.token,
      token_type: data.token_type,
    };
    
    setUser(data.user);
    setSession(authSession);
    
    // Set auth session in API service
    viewsMaxApi.setAuthSession({
      token: data.token,
      token_type: data.token_type
    });
    
    // Store in localStorage for persistence
    localStorage.setItem('auth_session', JSON.stringify(authSession));
  };

  const signOut = async () => {
    try {
      // Clear auth data
      setUser(null);
      setSession(null);
      localStorage.removeItem('auth_session');
      
      // Clear auth session from API service
      viewsMaxApi.setAuthSession(null);

      // Clear subscription data
      localStorage.removeItem('active_subscription');

      // Clear any other auth-related data
      Object.keys(localStorage).forEach((key) => {
        if (key.startsWith('supabase.auth.') || key.includes('sb-')) {
          localStorage.removeItem(key);
        }
      });
      
      // Use replace to avoid history entry and bypass React Router navigation
      window.location.replace('/');
    } catch (error) {
      console.error('Sign out error:', error);
      window.location.replace('/');
    }
  };


  const refreshUser = async () => {
    if (!session?.token) return;

    try {
      const response = await viewsMaxApi.getUserProfile();
      if (response.success && response.data) {
        // Create updated session with new user data but keep existing token
        const updatedUser = response.data.user;
        const updatedSession = {
          ...session,
          user: updatedUser
        };

        setUser(updatedUser);
        setSession(updatedSession);

        // Update local storage
        localStorage.setItem('auth_session', JSON.stringify(updatedSession));
      }
    } catch (error) {
      console.error('Failed to refresh user profile:', error);
    }
  };

  useEffect(() => {
    // Check for stored auth session
    const storedSession = localStorage.getItem('auth_session');
    if (storedSession) {
      try {
        const parsedSession = JSON.parse(storedSession);
        setUser(parsedSession.user);
        setSession(parsedSession);
        
        // Set auth session in API service
        viewsMaxApi.setAuthSession({
          token: parsedSession.token,
          token_type: parsedSession.token_type
        });
      } catch (error) {
        console.error('Error parsing stored session:', error);
        localStorage.removeItem('auth_session');
      }
    }
    setLoading(false);
  }, []);

  return (
    <AuthContext.Provider value={{ user, session, loading, signOut, setAuthData, refreshUser }}>
      {children}
    </AuthContext.Provider>
  );
};