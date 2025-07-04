// src/stores/authStore.ts
import { defineStore } from 'pinia';
import apiService from '@/services/apiService';
import { tokenManager } from '@/utils/tokenManager';
import type { User, Role, Permission } from '@/types/user';
import type { UserResponse } from '@/types/user';
import router from '@/router';

import { usePageMenuStore } from '@/stores/pageMenuStore';
import { useConferenceStore } from '@/stores/conferenceStore';
import { useUniversityStore } from '@/stores/universityStore';

// Import the auth types
import type {
  LoginRequest,
  RegisterRequest,
  ChangePasswordRequest,
  LoginResponse,
  RegisterResponse,
  RefreshTokenResponse,
  ChangePasswordResponse,
  AuthResponse,
} from '@/types/auth';


interface AuthState {
  user: User | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  error: string | null;
}

export const useAuthStore = defineStore('auth', {
  state: (): AuthState => ({
    user: null,
    isAuthenticated: false,
    isLoading: false,
    error: null,
  }),

  getters: {
    isEditor: (state) => state.user?.roles.some(role => role.name === 'editor') || false,
    isAdmin: (state) => state.user?.permissions.some(permission => permission.name === 'access.admin') || false,
    isSuperAdmin: (state) => state.user?.roles.some(role => role.name === 'super_admin') || false,

    hasEditorAccess: (state) => state.user?.permissions.some(permission => permission.name === 'access.editor') || false,
    hasAdminAccess: (state) => state.user?.permissions.some(permission => permission.name === 'access.admin') || false,
    hasSuperAdminAccess: (state) => state.user?.permissions.some(permission => permission.name === 'access.super_admin') || false,

    hasRole: (state) => (roleName: string) => state.user?.roles.some(role => role.name === roleName) || false,
    hasPermission: (state) => (permissionName: string) => state.user?.permissions.some(permission => permission.name === permissionName) || false,

    // Get token from localStorage instead of state
    getToken: () => tokenManager.getAccessToken(),
    getUser: (state) => state.user,
    getIsAuthenticated: (state) => state.isAuthenticated,
    getIsLoading: (state) => state.isLoading,
    getRoles: (state) => state.user?.roles || [],
    getRoleNames: (state) => state.user?.roles.map(role => role.name) || [],
    getPermissions: (state) => state.user?.permissions || [],
    getPermissionNames: (state) => state.user?.permissions.map(permission => permission.name) || [],
    getError: (state) => state.error,
  },

  actions: {
    setUserData(authResponse: AuthResponse) {
      this.user = authResponse.user;
      this.isAuthenticated = true;
      this.error = null;
      
      // Store tokens in localStorage
      tokenManager.setTokens(authResponse.access_token, authResponse.refresh_token);
    },

    clearUserData() {
      this.user = null;
      this.isAuthenticated = false;
      this.error = null;
      
      // Clear tokens from localStorage
      tokenManager.clearAllTokens();
    },

    setError(error: string) {
      this.error = error;
    },

    async login(credentials: LoginRequest) {
      this.isLoading = true;
      this.error = null;

      try {
        const refreshSuccess = await this.tryRefreshToken();

        if (refreshSuccess) {
          return true;
        }

        const response = await apiService.auth.login(credentials.email, credentials.password);
        const authData = response.data.payload;
        this.setUserData(authData);
        return true;
      } catch (error: any) {
        console.log(error);
        this.isAuthenticated = false;
        this.error = error.response?.data?.message || 'Login failed';
        return false;
      } finally {
        this.isLoading = false;
      }
    },

    async register(credentials: RegisterRequest) {
      this.isLoading = true;
      this.error = null;

      try {
        const response = await apiService.auth.register(
          credentials.name,
          credentials.email,
          credentials.password,
          credentials.password_confirmation
        );
        const authData = response.data.payload;
        this.setUserData(authData);
        return true;
      } catch (error: any) {
        this.isAuthenticated = false;
        this.error = error.response?.data?.message || 'Registration failed';
        return false;
      } finally {
        this.isLoading = false;
      }
    },

    async logout() {
      this.isLoading = true;

      try {
        await apiService.auth.logout();
      } catch (error: any) {
        this.error = error.response?.data?.message || 'Logout failed';
      } finally {
        this.clearUserData();

        const conferenceStore = useConferenceStore();
        const universityStore = useUniversityStore();
        const pageMenuStore = usePageMenuStore();
        
        conferenceStore.resetState();
        universityStore.resetState();
        pageMenuStore.resetState();

        this.isLoading = false;
        router.push({ name: 'Login' });
      }
    },


    async refreshToken() {
      this.isLoading = true;

      try {
        const response = await apiService.auth.refresh();
        const authData = response.data.payload;
        this.setUserData(authData);
        return true;
      } catch (error: any) {
        this.clearUserData();
        this.error = error.response?.data?.message || 'Session expired';
        router.push({ name: 'Login' });
        return false;
      } finally {
        this.isLoading = false;
      }
    },

    async tryRefreshToken() {
      try {
        // Check if refresh token exists before attempting refresh
        if (!tokenManager.getRefreshToken()) {
          return false;
        }

        const response = await apiService.auth.refresh();
        const authData = response.data.payload;
        this.setUserData(authData);
        return true;
      } catch (error: any) {
        console.log('Silent refresh failed:', error.response?.data?.message || 'Session expired');
        this.clearUserData();
        return false;
      }
    },

    async fetchCurrentUser() {
      this.isLoading = true;
      try {
        const response = await apiService.get<UserResponse>('/v1/user-management/users/me');
        this.user = response.data.payload;
        this.isAuthenticated = true;
        return true;
      } catch (error: any) {
        this.error = error.response?.data?.message || 'Failed to fetch user data';
        return false;
      } finally {
        this.isLoading = false;
      }
    },

    async changePassword(credentials: ChangePasswordRequest) {
      this.isLoading = true;
      this.error = null;

      try {
        const response = await apiService.auth.changePassword(
          credentials.new_password,
          credentials.new_password_confirmation
        );
        
        // Update access token if provided
        const newAccessToken = response.data.payload.access_token;
        const currentRefreshToken = tokenManager.getRefreshToken();
        const newRefreshToken = response.data.payload.refresh_token;
        
        if (newAccessToken) {
          tokenManager.setAccessToken(newAccessToken);
        }
        
        if (newRefreshToken) {
          tokenManager.setRefreshToken(newRefreshToken);
        } else if (currentRefreshToken) {
          // Keep existing refresh token if new one not provided
          tokenManager.setRefreshToken(currentRefreshToken);
        }
        
        return true;
      } catch (error: any) {
        this.error = error.response?.data?.message || 'Failed to change password';
        return false;
      } finally {
        this.isLoading = false;
      }
    },

    async checkAuth() {
      if (this.user && tokenManager.getAccessToken()) {
        this.isAuthenticated = true;
        return true;
      }

      try {
        const success = await this.refreshToken();
        return success;
      } catch (error) {
        this.clearUserData();
        return false;
      }
    }
  }
});