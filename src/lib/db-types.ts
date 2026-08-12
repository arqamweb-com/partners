export type Json =
  | string
  | number
  | boolean
  | null
  | { [key: string]: Json | undefined }
  | Json[]

export type Database = {
  // Allows to automatically instantiate createClient with right options
  // instead of createClient<Database, { PostgrestVersion: 'XX' }>(URL, KEY)
  __InternalSupabase: {
    PostgrestVersion: "14.15"
  }
  public: {
    Tables: {
      access_items: {
        Row: {
          created_at: string
          id: string
          is_done: boolean
          is_slow: boolean
          item_order: number
          name: string
          note: string
          project_id: string
          provided_at: string | null
          provided_by: string | null
          updated_at: string
        }
        Insert: {
          created_at?: string
          id?: string
          is_done?: boolean
          is_slow?: boolean
          item_order?: number
          name: string
          note?: string
          project_id: string
          provided_at?: string | null
          provided_by?: string | null
          updated_at?: string
        }
        Update: {
          created_at?: string
          id?: string
          is_done?: boolean
          is_slow?: boolean
          item_order?: number
          name?: string
          note?: string
          project_id?: string
          provided_at?: string | null
          provided_by?: string | null
          updated_at?: string
        }
        Relationships: [
          {
            foreignKeyName: "access_items_project_id_fkey"
            columns: ["project_id"]
            isOneToOne: false
            referencedRelation: "projects"
            referencedColumns: ["id"]
          },
        ]
      }
      app_settings: {
        Row: {
          freeze_threshold_days: number
          id: boolean
          reactivation_fee: number
          revision_rounds_allowed: number
          stage_defaults: Json
          updated_at: string
          warning_threshold_days: number
          warranty_days: number
        }
        Insert: {
          freeze_threshold_days?: number
          id?: boolean
          reactivation_fee?: number
          revision_rounds_allowed?: number
          stage_defaults?: Json
          updated_at?: string
          warning_threshold_days?: number
          warranty_days?: number
        }
        Update: {
          freeze_threshold_days?: number
          id?: boolean
          reactivation_fee?: number
          revision_rounds_allowed?: number
          stage_defaults?: Json
          updated_at?: string
          warning_threshold_days?: number
          warranty_days?: number
        }
        Relationships: []
      }
      audit_log: {
        Row: {
          actor_id: string | null
          actor_name: string
          created_at: string
          description: string
          event_type: string
          id: string
          project_id: string
        }
        Insert: {
          actor_id?: string | null
          actor_name?: string
          created_at?: string
          description: string
          event_type: string
          id?: string
          project_id: string
        }
        Update: {
          actor_id?: string | null
          actor_name?: string
          created_at?: string
          description?: string
          event_type?: string
          id?: string
          project_id?: string
        }
        Relationships: [
          {
            foreignKeyName: "audit_log_project_id_fkey"
            columns: ["project_id"]
            isOneToOne: false
            referencedRelation: "projects"
            referencedColumns: ["id"]
          },
        ]
      }
      change_requests: {
        Row: {
          created_at: string
          currency: string
          decided_at: string | null
          decided_by: string | null
          decision_deadline: string | null
          decision_note: string
          delivery_impact_days: number
          description: string
          duration_days: number
          id: string
          price: number
          project_id: string
          requested_by: string | null
          quote_valid_until: string | null
          resubmitted_from: string | null
          sent_at: string | null
          source_feedback_item_id: string | null
          status: Database["public"]["Enums"]["cr_status"]
          title: string
          updated_at: string
        }
        Insert: {
          created_at?: string
          currency?: string
          decided_at?: string | null
          decided_by?: string | null
          decision_deadline?: string | null
          decision_note?: string
          delivery_impact_days?: number
          description?: string
          duration_days?: number
          id?: string
          price?: number
          project_id: string
          requested_by?: string | null
          quote_valid_until?: string | null
          resubmitted_from?: string | null
          sent_at?: string | null
          source_feedback_item_id?: string | null
          status?: Database["public"]["Enums"]["cr_status"]
          title: string
          updated_at?: string
        }
        Update: {
          created_at?: string
          currency?: string
          decided_at?: string | null
          decided_by?: string | null
          decision_deadline?: string | null
          decision_note?: string
          delivery_impact_days?: number
          description?: string
          duration_days?: number
          id?: string
          price?: number
          project_id?: string
          requested_by?: string | null
          quote_valid_until?: string | null
          resubmitted_from?: string | null
          sent_at?: string | null
          source_feedback_item_id?: string | null
          status?: Database["public"]["Enums"]["cr_status"]
          title?: string
          updated_at?: string
        }
        Relationships: [
          {
            foreignKeyName: "change_requests_project_id_fkey"
            columns: ["project_id"]
            isOneToOne: false
            referencedRelation: "projects"
            referencedColumns: ["id"]
          },
          {
            foreignKeyName: "change_requests_resubmitted_from_fkey"
            columns: ["resubmitted_from"]
            isOneToOne: false
            referencedRelation: "change_requests"
            referencedColumns: ["id"]
          },
          {
            foreignKeyName: "change_requests_source_feedback_item_id_fkey"
            columns: ["source_feedback_item_id"]
            isOneToOne: false
            referencedRelation: "feedback_items"
            referencedColumns: ["id"]
          },
        ]
      }
      content_items: {
        Row: {
          acceptance_criteria: string
          auto_accepted: boolean
          created_at: string
          due_at: string | null
          id: string
          item_group: Database["public"]["Enums"]["content_group"]
          item_order: number
          name: string
          project_id: string
          rejection_reason: string
          reviewed_at: string | null
          reviewed_by: string | null
          status: Database["public"]["Enums"]["content_status"]
          submitted_at: string | null
          submitted_by: string | null
          updated_at: string
          value: string
        }
        Insert: {
          acceptance_criteria?: string
          auto_accepted?: boolean
          created_at?: string
          due_at?: string | null
          id?: string
          item_group: Database["public"]["Enums"]["content_group"]
          item_order?: number
          name: string
          project_id: string
          rejection_reason?: string
          reviewed_at?: string | null
          reviewed_by?: string | null
          status?: Database["public"]["Enums"]["content_status"]
          submitted_at?: string | null
          submitted_by?: string | null
          updated_at?: string
          value?: string
        }
        Update: {
          acceptance_criteria?: string
          auto_accepted?: boolean
          created_at?: string
          due_at?: string | null
          id?: string
          item_group?: Database["public"]["Enums"]["content_group"]
          item_order?: number
          name?: string
          project_id?: string
          rejection_reason?: string
          reviewed_at?: string | null
          reviewed_by?: string | null
          status?: Database["public"]["Enums"]["content_status"]
          submitted_at?: string | null
          submitted_by?: string | null
          updated_at?: string
          value?: string
        }
        Relationships: [
          {
            foreignKeyName: "content_items_project_id_fkey"
            columns: ["project_id"]
            isOneToOne: false
            referencedRelation: "projects"
            referencedColumns: ["id"]
          },
        ]
      }
      cr_price_items: {
        Row: {
          created_at: string
          currency: string
          duration_days: number
          id: string
          name: string
          price: number
        }
        Insert: {
          created_at?: string
          currency?: string
          duration_days?: number
          id?: string
          name: string
          price?: number
        }
        Update: {
          created_at?: string
          currency?: string
          duration_days?: number
          id?: string
          name?: string
          price?: number
        }
        Relationships: []
      }
      feedback_items: {
        Row: {
          classification:
            | Database["public"]["Enums"]["feedback_classification"]
            | null
          classified_at: string | null
          classified_by: string | null
          created_at: string
          description: string
          id: string
          objection_at: string | null
          objection_text: string
          page_or_section: string
          project_id: string
          resolution: Database["public"]["Enums"]["feedback_resolution"] | null
          round_id: string
          updated_at: string
        }
        Insert: {
          classification?:
            | Database["public"]["Enums"]["feedback_classification"]
            | null
          classified_at?: string | null
          classified_by?: string | null
          created_at?: string
          description: string
          id?: string
          objection_at?: string | null
          objection_text?: string
          page_or_section?: string
          project_id: string
          resolution?: Database["public"]["Enums"]["feedback_resolution"] | null
          round_id: string
          updated_at?: string
        }
        Update: {
          classification?:
            | Database["public"]["Enums"]["feedback_classification"]
            | null
          classified_at?: string | null
          classified_by?: string | null
          created_at?: string
          description?: string
          id?: string
          objection_at?: string | null
          objection_text?: string
          page_or_section?: string
          project_id?: string
          resolution?: Database["public"]["Enums"]["feedback_resolution"] | null
          round_id?: string
          updated_at?: string
        }
        Relationships: [
          {
            foreignKeyName: "feedback_items_project_id_fkey"
            columns: ["project_id"]
            isOneToOne: false
            referencedRelation: "projects"
            referencedColumns: ["id"]
          },
          {
            foreignKeyName: "feedback_items_round_id_fkey"
            columns: ["round_id"]
            isOneToOne: false
            referencedRelation: "feedback_rounds"
            referencedColumns: ["id"]
          },
        ]
      }
      feedback_rounds: {
        Row: {
          closed_at: string | null
          created_at: string
          id: string
          is_paid: boolean
          opened_at: string
          project_id: string
          round_number: number
          stage_id: string | null
          status: Database["public"]["Enums"]["feedback_round_status"]
          submitted_at: string | null
        }
        Insert: {
          closed_at?: string | null
          created_at?: string
          id?: string
          is_paid?: boolean
          opened_at?: string
          project_id: string
          round_number?: number
          stage_id?: string | null
          status?: Database["public"]["Enums"]["feedback_round_status"]
          submitted_at?: string | null
        }
        Update: {
          closed_at?: string | null
          created_at?: string
          id?: string
          is_paid?: boolean
          opened_at?: string
          project_id?: string
          round_number?: number
          stage_id?: string | null
          status?: Database["public"]["Enums"]["feedback_round_status"]
          submitted_at?: string | null
        }
        Relationships: [
          {
            foreignKeyName: "feedback_rounds_project_id_fkey"
            columns: ["project_id"]
            isOneToOne: false
            referencedRelation: "projects"
            referencedColumns: ["id"]
          },
          {
            foreignKeyName: "feedback_rounds_stage_id_fkey"
            columns: ["stage_id"]
            isOneToOne: false
            referencedRelation: "stages"
            referencedColumns: ["id"]
          },
        ]
      }
      gate_approvals: {
        Row: {
          acknowledgement_text: string
          approved_at: string
          approved_by: string | null
          approver_name: string
          id: string
          is_silent: boolean
          project_id: string
          stage_id: string
        }
        Insert: {
          acknowledgement_text: string
          approved_at?: string
          approved_by?: string | null
          approver_name: string
          id?: string
          is_silent?: boolean
          project_id: string
          stage_id: string
        }
        Update: {
          acknowledgement_text?: string
          approved_at?: string
          approved_by?: string | null
          approver_name?: string
          id?: string
          is_silent?: boolean
          project_id?: string
          stage_id?: string
        }
        Relationships: [
          {
            foreignKeyName: "gate_approvals_project_id_fkey"
            columns: ["project_id"]
            isOneToOne: false
            referencedRelation: "projects"
            referencedColumns: ["id"]
          },
          {
            foreignKeyName: "gate_approvals_stage_id_fkey"
            columns: ["stage_id"]
            isOneToOne: false
            referencedRelation: "stages"
            referencedColumns: ["id"]
          },
        ]
      }
      holidays: {
        Row: {
          holiday_date: string
          id: string
          label: string
        }
        Insert: {
          holiday_date: string
          id?: string
          label?: string
        }
        Update: {
          holiday_date?: string
          id?: string
          label?: string
        }
        Relationships: []
      }
      profiles: {
        Row: {
          agency_name: string | null
          created_at: string
          email: string
          full_name: string
          id: string
        }
        Insert: {
          agency_name?: string | null
          created_at?: string
          email?: string
          full_name?: string
          id: string
        }
        Update: {
          agency_name?: string | null
          created_at?: string
          email?: string
          full_name?: string
          id?: string
        }
        Relationships: []
      }
      project_invites: {
        Row: {
          claimed_at: string | null
          created_at: string
          email: string
          id: string
          invited_by: string | null
          project_id: string
        }
        Insert: {
          claimed_at?: string | null
          created_at?: string
          email: string
          id?: string
          invited_by?: string | null
          project_id: string
        }
        Update: {
          claimed_at?: string | null
          created_at?: string
          email?: string
          id?: string
          invited_by?: string | null
          project_id?: string
        }
        Relationships: []
      }
      project_members: {
        Row: {
          id: string
          project_id: string
          user_id: string
        }
        Insert: {
          id?: string
          project_id: string
          user_id: string
        }
        Update: {
          id?: string
          project_id?: string
          user_id?: string
        }
        Relationships: [
          {
            foreignKeyName: "project_members_project_id_fkey"
            columns: ["project_id"]
            isOneToOne: false
            referencedRelation: "projects"
            referencedColumns: ["id"]
          },
        ]
      }
      projects: {
        Row: {
          adjusted_delivery_date: string | null
          client_delay_days: number
          created_at: string
          credit_amount: number
          credit_expires_at: string | null
          end_client_name: string
          frozen_at: string | null
          id: string
          name: string
          notes: string
          original_delivery_date: string | null
          out_of_scope: string
          owner_id: string | null
          owner_name: string
          partner_agency: string
          project_type: string
          type_details: Json | null
          intake_data: Json | null
          payment_milestones: Json
          queue_slot_date: string | null
          reactivated_at: string | null
          reactivation_fee: number
          revision_rounds_allowed: number
          revision_rounds_used: number
          scope: string
          status: Database["public"]["Enums"]["project_status"]
          supported_browsers: string
          supported_devices: string
          supported_screens: string
          track: Database["public"]["Enums"]["project_track"]
          updated_at: string
          warranty_days: number
        }
        Insert: {
          adjusted_delivery_date?: string | null
          client_delay_days?: number
          created_at?: string
          credit_amount?: number
          credit_expires_at?: string | null
          end_client_name?: string
          frozen_at?: string | null
          id?: string
          name: string
          notes?: string
          original_delivery_date?: string | null
          out_of_scope?: string
          owner_id?: string | null
          owner_name?: string
          partner_agency?: string
          project_type?: string
          type_details?: Json | null
          intake_data?: Json | null
          payment_milestones?: Json
          queue_slot_date?: string | null
          reactivated_at?: string | null
          reactivation_fee?: number
          revision_rounds_allowed?: number
          revision_rounds_used?: number
          scope?: string
          status?: Database["public"]["Enums"]["project_status"]
          supported_browsers?: string
          supported_devices?: string
          supported_screens?: string
          track?: Database["public"]["Enums"]["project_track"]
          updated_at?: string
          warranty_days?: number
        }
        Update: {
          adjusted_delivery_date?: string | null
          client_delay_days?: number
          created_at?: string
          credit_amount?: number
          credit_expires_at?: string | null
          end_client_name?: string
          frozen_at?: string | null
          id?: string
          name?: string
          notes?: string
          original_delivery_date?: string | null
          out_of_scope?: string
          owner_id?: string | null
          owner_name?: string
          partner_agency?: string
          project_type?: string
          type_details?: Json | null
          intake_data?: Json | null
          payment_milestones?: Json
          queue_slot_date?: string | null
          reactivated_at?: string | null
          reactivation_fee?: number
          revision_rounds_allowed?: number
          revision_rounds_used?: number
          scope?: string
          status?: Database["public"]["Enums"]["project_status"]
          supported_browsers?: string
          supported_devices?: string
          supported_screens?: string
          track?: Database["public"]["Enums"]["project_track"]
          updated_at?: string
          warranty_days?: number
        }
        Relationships: []
      }
      stages: {
        Row: {
          ball_in_court: Database["public"]["Enums"]["ball_in_court"]
          created_at: string
          deliverables: Json
          due_at: string | null
          gate_name: string | null
          gate_size: string
          id: string
          is_parallel: boolean
          locked_at: string | null
          locked_by: string | null
          name: string
          our_duration_days: number
          project_id: string
          rejected_at: string | null
          rejected_by: string | null
          rejection_count: number
          rejection_reason: string | null
          submission_note: string | null
          submitted_at: string | null
          submitted_by: string | null
          stage_index: number
          started_at: string | null
          status: Database["public"]["Enums"]["stage_status"]
          their_duration_days: number
        }
        Insert: {
          ball_in_court?: Database["public"]["Enums"]["ball_in_court"]
          created_at?: string
          deliverables?: Json
          due_at?: string | null
          gate_name?: string | null
          gate_size?: string
          id?: string
          is_parallel?: boolean
          locked_at?: string | null
          locked_by?: string | null
          name: string
          our_duration_days?: number
          project_id: string
          rejected_at?: string | null
          rejected_by?: string | null
          rejection_count?: number
          rejection_reason?: string | null
          submission_note?: string | null
          submitted_at?: string | null
          submitted_by?: string | null
          stage_index: number
          started_at?: string | null
          status?: Database["public"]["Enums"]["stage_status"]
          their_duration_days?: number
        }
        Update: {
          ball_in_court?: Database["public"]["Enums"]["ball_in_court"]
          created_at?: string
          deliverables?: Json
          due_at?: string | null
          gate_name?: string | null
          gate_size?: string
          id?: string
          is_parallel?: boolean
          locked_at?: string | null
          locked_by?: string | null
          name?: string
          our_duration_days?: number
          project_id?: string
          rejected_at?: string | null
          rejected_by?: string | null
          rejection_count?: number
          rejection_reason?: string | null
          submission_note?: string | null
          submitted_at?: string | null
          submitted_by?: string | null
          stage_index?: number
          started_at?: string | null
          status?: Database["public"]["Enums"]["stage_status"]
          their_duration_days?: number
        }
        Relationships: [
          {
            foreignKeyName: "stages_project_id_fkey"
            columns: ["project_id"]
            isOneToOne: false
            referencedRelation: "projects"
            referencedColumns: ["id"]
          },
        ]
      }
      user_roles: {
        Row: {
          id: string
          role: Database["public"]["Enums"]["app_role"]
          user_id: string
        }
        Insert: {
          id?: string
          role: Database["public"]["Enums"]["app_role"]
          user_id: string
        }
        Update: {
          id?: string
          role?: Database["public"]["Enums"]["app_role"]
          user_id?: string
        }
        Relationships: []
      }
    }
    Views: {
      [_ in never]: never
    }
    Functions: {
      add_business_days: {
        Args: { _days: number; _from: string }
        Returns: string
      }
      has_role: {
        Args: {
          _role: Database["public"]["Enums"]["app_role"]
          _user_id: string
        }
        Returns: boolean
      }
      is_project_member: {
        Args: { _project_id: string; _user_id: string }
        Returns: boolean
      }
    }
    Enums: {
      app_role: "admin" | "client"
      ball_in_court: "us" | "them"
      content_group: "blocking" | "non_blocking"
      content_status: "pending" | "submitted" | "accepted" | "rejected"
      cr_status:
        | "draft"
        | "sent"
        | "approved"
        | "rejected"
        | "expired"
        | "withdrawn"
      feedback_classification: "defect" | "enhancement" | "new_scope"
      feedback_resolution: "fixed" | "converted_to_cr" | "goodwill_fix"
      feedback_round_status: "open" | "submitted" | "classified" | "closed"
      project_status:
        | "draft"
        | "active"
        | "awaiting_client"
        | "frozen"
        | "completed"
        | "stopped"
      project_track: "normal" | "fast_track"
      stage_status:
        | "pending"
        | "active"
        | "awaiting_approval"
        | "locked"
        | "frozen"
    }
    CompositeTypes: {
      [_ in never]: never
    }
  }
}

type DatabaseWithoutInternals = Omit<Database, "__InternalSupabase">

type DefaultSchema = DatabaseWithoutInternals[Extract<keyof Database, "public">]

export type Tables<
  DefaultSchemaTableNameOrOptions extends
    | keyof (DefaultSchema["Tables"] & DefaultSchema["Views"])
    | { schema: keyof DatabaseWithoutInternals },
  TableName extends DefaultSchemaTableNameOrOptions extends {
    schema: keyof DatabaseWithoutInternals
  }
    ? keyof (DatabaseWithoutInternals[DefaultSchemaTableNameOrOptions["schema"]]["Tables"] &
        DatabaseWithoutInternals[DefaultSchemaTableNameOrOptions["schema"]]["Views"])
    : never = never,
> = DefaultSchemaTableNameOrOptions extends {
  schema: keyof DatabaseWithoutInternals
}
  ? (DatabaseWithoutInternals[DefaultSchemaTableNameOrOptions["schema"]]["Tables"] &
      DatabaseWithoutInternals[DefaultSchemaTableNameOrOptions["schema"]]["Views"])[TableName] extends {
      Row: infer R
    }
    ? R
    : never
  : DefaultSchemaTableNameOrOptions extends keyof (DefaultSchema["Tables"] &
        DefaultSchema["Views"])
    ? (DefaultSchema["Tables"] &
        DefaultSchema["Views"])[DefaultSchemaTableNameOrOptions] extends {
        Row: infer R
      }
      ? R
      : never
    : never

export type TablesInsert<
  DefaultSchemaTableNameOrOptions extends
    | keyof DefaultSchema["Tables"]
    | { schema: keyof DatabaseWithoutInternals },
  TableName extends DefaultSchemaTableNameOrOptions extends {
    schema: keyof DatabaseWithoutInternals
  }
    ? keyof DatabaseWithoutInternals[DefaultSchemaTableNameOrOptions["schema"]]["Tables"]
    : never = never,
> = DefaultSchemaTableNameOrOptions extends {
  schema: keyof DatabaseWithoutInternals
}
  ? DatabaseWithoutInternals[DefaultSchemaTableNameOrOptions["schema"]]["Tables"][TableName] extends {
      Insert: infer I
    }
    ? I
    : never
  : DefaultSchemaTableNameOrOptions extends keyof DefaultSchema["Tables"]
    ? DefaultSchema["Tables"][DefaultSchemaTableNameOrOptions] extends {
        Insert: infer I
      }
      ? I
      : never
    : never

export type TablesUpdate<
  DefaultSchemaTableNameOrOptions extends
    | keyof DefaultSchema["Tables"]
    | { schema: keyof DatabaseWithoutInternals },
  TableName extends DefaultSchemaTableNameOrOptions extends {
    schema: keyof DatabaseWithoutInternals
  }
    ? keyof DatabaseWithoutInternals[DefaultSchemaTableNameOrOptions["schema"]]["Tables"]
    : never = never,
> = DefaultSchemaTableNameOrOptions extends {
  schema: keyof DatabaseWithoutInternals
}
  ? DatabaseWithoutInternals[DefaultSchemaTableNameOrOptions["schema"]]["Tables"][TableName] extends {
      Update: infer U
    }
    ? U
    : never
  : DefaultSchemaTableNameOrOptions extends keyof DefaultSchema["Tables"]
    ? DefaultSchema["Tables"][DefaultSchemaTableNameOrOptions] extends {
        Update: infer U
      }
      ? U
      : never
    : never

export type Enums<
  DefaultSchemaEnumNameOrOptions extends
    | keyof DefaultSchema["Enums"]
    | { schema: keyof DatabaseWithoutInternals },
  EnumName extends DefaultSchemaEnumNameOrOptions extends {
    schema: keyof DatabaseWithoutInternals
  }
    ? keyof DatabaseWithoutInternals[DefaultSchemaEnumNameOrOptions["schema"]]["Enums"]
    : never = never,
> = DefaultSchemaEnumNameOrOptions extends {
  schema: keyof DatabaseWithoutInternals
}
  ? DatabaseWithoutInternals[DefaultSchemaEnumNameOrOptions["schema"]]["Enums"][EnumName]
  : DefaultSchemaEnumNameOrOptions extends keyof DefaultSchema["Enums"]
    ? DefaultSchema["Enums"][DefaultSchemaEnumNameOrOptions]
    : never

export type CompositeTypes<
  PublicCompositeTypeNameOrOptions extends
    | keyof DefaultSchema["CompositeTypes"]
    | { schema: keyof DatabaseWithoutInternals },
  CompositeTypeName extends PublicCompositeTypeNameOrOptions extends {
    schema: keyof DatabaseWithoutInternals
  }
    ? keyof DatabaseWithoutInternals[PublicCompositeTypeNameOrOptions["schema"]]["CompositeTypes"]
    : never = never,
> = PublicCompositeTypeNameOrOptions extends {
  schema: keyof DatabaseWithoutInternals
}
  ? DatabaseWithoutInternals[PublicCompositeTypeNameOrOptions["schema"]]["CompositeTypes"][CompositeTypeName]
  : PublicCompositeTypeNameOrOptions extends keyof DefaultSchema["CompositeTypes"]
    ? DefaultSchema["CompositeTypes"][PublicCompositeTypeNameOrOptions]
    : never

export const Constants = {
  public: {
    Enums: {
      app_role: ["admin", "client"],
      ball_in_court: ["us", "them"],
      content_group: ["blocking", "non_blocking"],
      content_status: ["pending", "submitted", "accepted", "rejected"],
      cr_status: [
        "draft",
        "sent",
        "approved",
        "rejected",
        "expired",
        "withdrawn",
      ],
      feedback_classification: ["defect", "enhancement", "new_scope"],
      feedback_resolution: ["fixed", "converted_to_cr", "goodwill_fix"],
      feedback_round_status: ["open", "submitted", "classified", "closed"],
      project_status: [
        "draft",
        "active",
        "awaiting_client",
        "frozen",
        "completed",
        "stopped",
      ],
      project_track: ["normal", "fast_track"],
      stage_status: [
        "pending",
        "active",
        "awaiting_approval",
        "locked",
        "frozen",
      ],
    },
  },
} as const
